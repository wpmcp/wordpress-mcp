<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Modula_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Modula (issue #705): free-tier ops on the plugin-data dispatcher pair.
 *
 * Modula is not loaded in the shared test core (ModulaLiveTest runs the ops
 * against the real plugin). Presence is driven through wpmcp_modula_active,
 * and its post type is registered here the way Modula 3.0 does in
 * includes/admin/cpt/class-modula-cpt.php: a gallery is a non-public
 * `modula-gallery` post with core's post capabilities, its images the
 * modula-images meta, a list of rows in display order, each naming its
 * attachment by id (Modula_Gallery_Upload::build_attachment_image_row_for_layout()).
 *
 * The writes are off until the site opts in (wpmcp_enable_modula_write) and
 * each is undone in a test.
 */
final class ModulaPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'modula-list-galleries' => 'read',
        'modula-get-gallery'    => 'read',
        'modula-create-gallery' => 'write',
        'modula-add-images'     => 'write',
        'modula-remove-images'  => 'write',
        'modula-reorder-images' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        if (! post_type_exists('modula-gallery')) {
            register_post_type('modula-gallery', [ 'public' => false, 'show_ui' => true, 'supports' => [ 'title' ] ]);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_modula_active', '__return_true');
        add_filter('wpmcp_enable_modula_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_modula_active');
        remove_all_filters('wpmcp_enable_modula_write');
        if (! defined('MODULA_LITE_VERSION')) {
            unregister_post_type('modula-gallery');
        }
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function image(string $title = 'Lake'): int
    {
        return self::factory()->attachment->create_object([
            'file'           => sanitize_title($title) . '.jpg',
            'post_mime_type' => 'image/jpeg',
            'post_title'     => $title,
            'post_status'    => 'inherit',
        ]);
    }

    /** A gallery as Modula's editor stores it. */
    private function gallery(string $title = 'Summer', array $images = [], string $status = 'publish'): int
    {
        $id   = self::factory()->post->create([ 'post_type' => 'modula-gallery', 'post_title' => $title, 'post_status' => $status ]);
        $rows = array_map(static fn (int $image): array => [ 'id' => $image, 'alt' => '', 'title' => '', 'description' => '', 'halign' => 'center', 'valign' => 'middle', 'link' => '', 'target' => 0, 'width' => 2, 'height' => 2 ], $images);
        update_post_meta($id, 'modula-settings', [ 'type' => 'grid' ]);
        update_post_meta($id, 'modula-images', $rows);
        return $id;
    }

    private function ids(int $gallery): array
    {
        return array_map('intval', array_column((array) get_post_meta($gallery, 'modula-images', true), 'id'));
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_modula_is_missing_a_call_answers_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_modula_active');
        add_filter('wpmcp_modula_active', '__return_false');
        $out = $this->read('modula-list-galleries');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Modula', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_modula_active');
        $this->assertSame(defined('MODULA_LITE_VERSION'), Modula_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $img = $this->image();
        $id  = $this->gallery('Summer', [ $img ]);
        remove_all_filters('wpmcp_enable_modula_write');
        foreach ([
            [ 'modula-create-gallery', [ 'title' => 'x' ] ],
            [ 'modula-add-images', [ 'id' => $id, 'image_ids' => [ $this->image('Other') ] ] ],
            [ 'modula-remove-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
            [ 'modula-reorder-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_modula_write', $out['error']['message']);
        }
        $this->assertSame([ $img ], $this->ids($id));
    }

    public function test_the_permission_decision_holds_a_contributor_to_their_own_galleries(): void
    {
        $img         = $this->image();
        $others      = $this->gallery('Summer', [ $img ]);
        $contributor = self::factory()->user->create([ 'role' => 'contributor' ]);
        $own         = self::factory()->post->create([ 'post_type' => 'modula-gallery', 'post_author' => $contributor, 'post_status' => 'draft' ]);
        [, $write]   = (new Plugin_Data_Integration())->abilities();
        $grant       = static function (array $caps) use ($write): array {
            $caps[ $write->capability ] = true;
            return $caps;
        };
        add_filter('user_has_cap', $grant);
        wp_set_current_user($contributor);
        $registrar = new \WPMCP\MCP\Registrar();
        try {
            $this->assertFalse($registrar->would_permit($write, [ 'operation' => 'modula-remove-images', 'args' => [ 'id' => $others, 'image_ids' => [ $img ] ] ]));
            $this->assertTrue($registrar->would_permit($write, [ 'operation' => 'modula-remove-images', 'args' => [ 'id' => $own, 'image_ids' => [ $img ] ] ]));
        } finally {
            remove_filter('user_has_cap', $grant);
        }
    }

    public function test_list_and_get_return_galleries_and_their_images_in_order(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $a   = $this->gallery('Alpha', [ $two, $one ]);
        $b   = $this->gallery('Beta', [], 'draft');
        update_post_meta($one, '_wp_attachment_image_alt', 'One alt');

        $out  = $this->ok($this->read('modula-list-galleries'));
        $rows = array_column($out['galleries'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame(2, $rows[ $a ]['image_count']);
        $this->assertSame('grid', $rows[ $a ]['type']);
        $this->assertSame('[modula id="' . $a . '"]', $rows[ $a ]['shortcode']);
        $this->assertSame([ $b ], array_column($this->read('modula-list-galleries', [ 'status' => 'draft' ])['galleries'], 'id'));

        $g = $this->ok($this->read('modula-get-gallery', [ 'id' => $a ]))['gallery'];
        $this->assertSame([ $two, $one ], $g['image_ids']);
        $this->assertSame('One', $g['images'][1]['title'], 'an empty row title reads the attachment\'s');
        $this->assertSame('One alt', $g['images'][1]['alt']);
        $this->assertSame('gallery_not_found', $this->read('modula-get-gallery', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_create_gallery_stores_a_draft_with_its_images_and_rollback_session_trashes_it(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $out = $this->ok($this->write('modula-create-gallery', [ 'title' => 'Wedding', 'image_ids' => [ $two, $one, $two ] ]));
        $id  = (int) $out['result']['gallery']['id'];
        $this->assertSame('modula-gallery', get_post_type($id));
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame([ $two, $one ], $this->ids($id), 'duplicates dropped, order kept');
        $row = get_post_meta($id, 'modula-images', true)[0];
        $this->assertSame('center', $row['halign']);
        $this->assertSame('middle', $row['valign']);

        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_gallery_refuses_an_id_that_is_not_an_attachment(): void
    {
        $out = $this->write('modula-create-gallery', [ 'title' => 'x', 'image_ids' => [ self::factory()->post->create() ] ]);
        $this->assertSame('image_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(0, (int) (new \WP_Query([ 'post_type' => 'modula-gallery', 'post_status' => 'any', 'fields' => 'ids' ]))->found_posts);
    }

    public function test_add_images_appends_without_duplicates_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one ]);

        $out = $this->ok($this->write('modula-add-images', [ 'id' => $id, 'image_ids' => [ $one, $two ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $one, $two ], $this->ids($id));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one ], $this->ids($id));
    }

    public function test_remove_images_drops_their_rows_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one, $two ]);

        $out = $this->ok($this->write('modula-remove-images', [ 'id' => $id, 'image_ids' => [ $one ] ]));
        $this->assertSame([ $two ], $this->ids($id));
        $this->assertSame('attachment', get_post_type($one), 'the media file stays');
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one, $two ], $this->ids($id));
    }

    public function test_reorder_images_keeps_each_row_and_needs_exactly_the_current_images(): void
    {
        $one   = $this->image('One');
        $two   = $this->image('Two');
        $three = $this->image('Three');
        $id    = $this->gallery('Alpha', [ $one, $two, $three ]);
        $rows  = get_post_meta($id, 'modula-images', true);
        $rows[2]['link'] = 'https://example.test/three';
        update_post_meta($id, 'modula-images', $rows);

        $this->assertSame('images_mismatch', $this->write('modula-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one ] ])['error']['code'] ?? null);

        $out = $this->ok($this->write('modula-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one, $two ] ]));
        $this->assertSame([ $three, $one, $two ], $this->ids($id));
        $this->assertSame('https://example.test/three', get_post_meta($id, 'modula-images', true)[0]['link'], 'rows move whole');
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one, $two, $three ], $this->ids($id));
    }
}
