<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #705 against the real NextGEN Gallery (bin/test-local.sh
 * --live-galleries, WPMCP_LIVE_GALLERIES=1): NextGEN's own installer creates
 * its tables, the ops read, reorder and change a gallery's images, and
 * NextGEN's own image mapper reads the result back. Its rendered gallery
 * cache (the displayed_gallery_rendering transient group) is checked after
 * each write and each undo. Skipped elsewhere; the live leg runs with
 * --fail-on-skipped.
 *
 * @group galleries-live
 */
final class NextGenLiveTest extends \WP_UnitTestCase
{
    private const CAPS = [ 'NextGEN Gallery overview', 'NextGEN Manage gallery', 'NextGEN Manage others gallery' ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('NGG_PLUGIN_VERSION') || ! class_exists('\\Imagely\\NGG\\DataMappers\\Image') || ! class_exists('\\Imagely\\NGG\\Util\\Transient')) {
            $this->markTestSkipped('Needs the real NextGEN Gallery (bin/test-local.sh --live-galleries, WPMCP_LIVE_GALLERIES=1).');
        }
        global $wpdb;
        if ($wpdb->prefix . 'ngg_pictures' !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'ngg_pictures'))) {
            ( new \C_NGG_Legacy_Installer() )->install();
        }
        Snapshot_Store::install();
        $user = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($user);
        if (is_multisite()) {
            grant_super_admin($user);
        }
        foreach (self::CAPS as $cap) {
            wp_get_current_user()->add_cap($cap);
        }
        add_filter('wpmcp_enable_nextgen_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_nextgen_write');
        parent::tearDown();
    }

    private function call(string $op, array $args, bool $write = true): array
    {
        $pair = new Plugin_Data_Integration();
        $out  = $write ? $pair->handle_write([ 'operation' => $op, 'args' => $args ]) : $pair->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function gallery(string $title): int
    {
        global $wpdb;
        $slug = sanitize_title($title);
        $wpdb->insert($wpdb->prefix . 'ngg_gallery', [ 'name' => $slug, 'slug' => $slug, 'path' => 'wp-content/gallery/' . $slug . '/', 'title' => $title, 'galdesc' => '', 'author' => get_current_user_id() ]);
        return (int) $wpdb->insert_id;
    }

    private function picture(int $gallery, string $file, int $sortorder): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ngg_pictures', [ 'image_slug' => sanitize_title($file), 'galleryid' => $gallery, 'filename' => $file, 'alttext' => pathinfo($file, PATHINFO_FILENAME), 'description' => '', 'sortorder' => $sortorder, 'meta_data' => '' ]);
        return (int) $wpdb->insert_id;
    }

    /** One image as NextGEN's own mapper reads it. */
    private function image(int $pid): object
    {
        $image = \Imagely\NGG\DataMappers\Image::get_instance()->find($pid);
        $this->assertIsObject($image, 'NextGEN finds the image');
        return $image;
    }

    /** @param int[] $pids @return int[] each image's sortorder, as NextGEN reads it */
    private function sortorders(array $pids): array
    {
        return array_map(fn (int $pid): int => (int) $this->image($pid)->sortorder, $pids);
    }

    private function cache_rendering(): string
    {
        $key = \Imagely\NGG\Util\Transient::create_key('displayed_gallery_rendering', [ 'live' => uniqid('', true) ]);
        \Imagely\NGG\Util\Transient::update($key, '<div>cached gallery</div>');
        $this->assertTrue($this->cached($key));
        return $key;
    }

    /**
     * Whether the rendering is still stored. NextGEN drops a transient group
     * with a DELETE on the options table, so this reads the table rather than
     * this request's copy in the object cache, as the next page view would.
     */
    private function cached(string $key): bool
    {
        global $wpdb;
        return null !== $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key));
    }

    public function test_reorder_and_update_reach_nextgens_mapper_and_cache_and_roll_back(): void
    {
        $id = $this->gallery('Live gallery');
        $a  = $this->picture($id, 'first.jpg', 1);
        $b  = $this->picture($id, 'second.jpg', 2);
        $c  = $this->picture($id, 'third.jpg', 3);

        $read = $this->call('nextgen-get-gallery', [ 'id' => $id ], false)['result']['gallery'];
        $this->assertSame([ $a, $b, $c ], array_column($read['images'], 'id'));
        $this->assertSame([ 1, 2, 3 ], $this->sortorders([ $a, $b, $c ]), 'NextGEN reads the seeded order');

        $key   = $this->cache_rendering();
        $moved = $this->call('nextgen-reorder-images', [ 'id' => $id, 'image_ids' => [ $c, $a, $b ] ]);
        $this->assertSame([ 2, 3, 1 ], $this->sortorders([ $a, $b, $c ]), 'NextGEN reads the new order');
        $this->assertFalse($this->cached($key), 'the rendered gallery cache is dropped');

        $key = $this->cache_rendering();
        $this->assertGreaterThan(0, Rollback_Service::restore_session($moved['session_id']));
        $this->assertSame([ 1, 2, 3 ], $this->sortorders([ $a, $b, $c ]));
        $this->assertFalse($this->cached($key), 'the undo also drops the rendered gallery cache');

        $key  = $this->cache_rendering();
        $edit = $this->call('nextgen-update-image', [ 'image_id' => $b, 'alttext' => 'Middle', 'description' => 'At dusk', 'excluded' => true ]);
        $img  = $this->image($b);
        $this->assertSame('Middle', $img->alttext);
        $this->assertSame('At dusk', $img->description);
        $this->assertSame(1, (int) $img->exclude);
        $this->assertFalse($this->cached($key));

        $this->assertTrue(Rollback_Service::restore_operation($edit['operation_id']));
        $img = $this->image($b);
        $this->assertSame('second', $img->alttext);
        $this->assertSame('', $img->description);
        $this->assertSame(0, (int) $img->exclude);
    }
}
