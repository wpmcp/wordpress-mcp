<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #705 against the real Envira Gallery Lite (bin/test-local.sh
 * --live-galleries, WPMCP_LIVE_GALLERIES=1): the ops create a gallery of
 * real uploaded images, reorder, remove and add them, and Envira's own
 * [envira-gallery] shortcode renders the result. Envira serves that render
 * from its _eg_cache_* transients, so each render after a write or an undo
 * also proves the cache was flushed. Skipped elsewhere; the live leg runs
 * with --fail-on-skipped.
 *
 * @group galleries-live
 */
final class EnviraLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ENVIRA_LITE_VERSION') || ! class_exists('Envira_Gallery_Common')) {
            $this->markTestSkipped('Needs the real Envira Gallery Lite (bin/test-local.sh --live-galleries, WPMCP_LIVE_GALLERIES=1).');
        }
        if (class_exists('\\Envira\\Admin\\Envira_Capabilities')) {
            \Envira\Admin\Envira_Capabilities::register_capabilities();
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_envira_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_envira_write');
        parent::tearDown();
    }

    private function call(string $op, array $args): array
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function upload(string $name): int
    {
        $file = get_temp_dir() . $name . '.jpg';
        copy(DIR_TESTDATA . '/images/canola.jpg', $file);
        $id = (int) self::factory()->attachment->create_upload_object($file);
        @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- test scratch copy.
        return $id;
    }

    /** The images in the order Envira renders them. */
    private function rendered(int $gallery): array
    {
        $html = do_shortcode('[envira-gallery id="' . $gallery . '"]');
        preg_match_all('/(ev-first|ev-second|ev-third)[^"\'\s]*\.jpg/', $html, $m);
        return array_values(array_unique(array_map(static fn (string $f): string => explode('-', $f)[1], $m[1])));
    }

    public function test_writes_render_through_envira_and_its_cache_follows_every_undo(): void
    {
        $first  = $this->upload('ev-first');
        $second = $this->upload('ev-second');
        $third  = $this->upload('ev-third');

        $made = $this->call('envira-create-gallery', [ 'title' => 'Live gallery', 'image_ids' => [ $first, $second ], 'status' => 'publish' ]);
        $id   = (int) $made['result']['gallery']['id'];
        $this->assertNotEmpty(get_post_meta($id, '_eg_gallery_data', true)['config']['gallery_theme'] ?? null, 'the config holds Envira\'s own defaults');
        $this->assertSame([ 'first', 'second' ], $this->rendered($id), 'Envira renders both images in order');

        $moved = $this->call('envira-reorder-images', [ 'id' => $id, 'image_ids' => [ $second, $first ] ]);
        $this->assertSame([ 'second', 'first' ], $this->rendered($id));
        $this->assertTrue(Rollback_Service::restore_operation($moved['operation_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($id), 'the undo reached the rendered (cached) gallery');

        $removed = $this->call('envira-remove-images', [ 'id' => $id, 'image_ids' => [ $first ] ]);
        $this->assertSame([ 'second' ], $this->rendered($id));
        $this->assertGreaterThan(0, Rollback_Service::restore_session($removed['session_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($id));
        $this->assertContains($id, array_map('intval', (array) get_post_meta($first, '_eg_has_gallery', true)));

        $added = $this->call('envira-add-images', [ 'id' => $id, 'image_ids' => [ $third ] ]);
        $this->assertSame([ 'first', 'second', 'third' ], $this->rendered($id));
        $this->assertGreaterThan(0, Rollback_Service::restore_session($added['session_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($id));
        $this->assertNotContains($id, array_map('intval', (array) get_post_meta($third, '_eg_has_gallery', true)));

        $this->assertGreaterThan(0, Rollback_Service::restore_session($made['session_id']));
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame([], $this->rendered($id));
    }
}
