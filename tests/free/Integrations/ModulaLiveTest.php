<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #705 against the real Modula (bin/test-local.sh --live-galleries,
 * WPMCP_LIVE_GALLERIES=1): the ops create a gallery of real uploaded images
 * (rows built by Modula's own Modula_Gallery_Upload), reorder and remove
 * them, and Modula's own [modula] shortcode renders the result, for a
 * classic gallery and for one of its newer editor, whose JSON image copy
 * Modula's Meta_Sync keeps in step. Each change is undone and the render
 * follows. Skipped elsewhere; the live leg runs with --fail-on-skipped.
 *
 * @group galleries-live
 */
final class ModulaLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('MODULA_LITE_VERSION') || ! class_exists('\\Modula\\V2\\Meta_Sync')) {
            $this->markTestSkipped('Needs the real Modula (bin/test-local.sh --live-galleries, WPMCP_LIVE_GALLERIES=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_modula_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_modula_write');
        parent::tearDown();
    }

    private function call(string $op, array $args, bool $write = true): array
    {
        $pair = new Plugin_Data_Integration();
        $out  = $write ? $pair->handle_write([ 'operation' => $op, 'args' => $args ]) : $pair->handle_read([ 'operation' => $op, 'args' => $args ]);
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

    /** The images in the order Modula renders them. */
    private function rendered(int $gallery): array
    {
        $html = do_shortcode('[modula id="' . $gallery . '"]');
        preg_match_all('/(md-first|md-second)[^"\'\s]*\.jpg/', $html, $m);
        return array_values(array_unique(array_map(static fn (string $f): string => str_starts_with($f, 'md-first') ? 'first' : 'second', $m[1])));
    }

    /** @return int[] The attachment ids of Modula's newer JSON copy, in order. */
    private function v2_ids(int $gallery): array
    {
        $ids = [];
        foreach ((array) \Modula\V2\Meta_Sync::get_images_v2($gallery) as $row) {
            $ids[] = (int) ($row['id'] ?? $row['attachmentId'] ?? $row['attachment_id'] ?? 0);
        }
        return $ids;
    }

    public function test_writes_render_through_modula_for_classic_and_newer_galleries_and_roll_back(): void
    {
        $first  = $this->upload('md-first');
        $second = $this->upload('md-second');

        $made = $this->call('modula-create-gallery', [ 'title' => 'Live gallery', 'image_ids' => [ $first, $second ], 'status' => 'publish' ]);
        $id   = (int) $made['result']['gallery']['id'];
        $this->assertSame([ $first, $second ], $made['result']['gallery']['image_ids']);
        $this->assertTrue(class_exists('Modula_Gallery_Upload', false), 'the rows came from Modula\'s own builder');
        $this->assertNotEmpty(get_post_meta($id, 'modula-settings', true), 'Modula wrote its default settings');
        $this->assertSame([ 'first', 'second' ], $this->rendered($id), 'Modula renders both images in order');

        $moved = $this->call('modula-reorder-images', [ 'id' => $id, 'image_ids' => [ $second, $first ] ]);
        $this->assertSame([ 'second', 'first' ], $this->rendered($id));
        $this->assertTrue(Rollback_Service::restore_operation($moved['operation_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($id));

        $removed = $this->call('modula-remove-images', [ 'id' => $id, 'image_ids' => [ $first ] ]);
        $this->assertSame([ 'second' ], $this->rendered($id));
        $this->assertTrue(Rollback_Service::restore_operation($removed['operation_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($id));

        // A gallery of Modula's newer editor renders from its JSON copy.
        update_post_meta($id, '_modula_beta', '1');
        \Modula\V2\Meta_Sync::sync_modula_images_v2_from_list($id, (array) get_post_meta($id, 'modula-images', true));
        $this->assertSame([ $first, $second ], $this->v2_ids($id));
        $moved = $this->call('modula-reorder-images', [ 'id' => $id, 'image_ids' => [ $second, $first ] ]);
        $this->assertSame([ $second, $first ], $this->v2_ids($id), 'Meta_Sync rebuilt the JSON copy');
        $this->assertSame([ 'second', 'first' ], $this->rendered($id));
        $this->assertTrue(Rollback_Service::restore_operation($moved['operation_id']));
        $this->assertSame([ $first, $second ], $this->v2_ids($id), 'the undo puts the JSON copy back too');
        $this->assertSame([ 'first', 'second' ], $this->rendered($id));

        $this->assertGreaterThan(0, Rollback_Service::restore_session($made['session_id']));
        $this->assertSame('trash', get_post_status($id));
    }
}
