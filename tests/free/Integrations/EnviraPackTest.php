<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Envira_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Envira Gallery Lite (issue #705): free-tier ops on the plugin-data pair.
 *
 * Envira is not loaded in the shared test core (EnviraLiveTest runs the ops
 * against the real plugin). Presence is driven through wpmcp_envira_active,
 * and its post type is registered here the way Envira Gallery Lite 1.17
 * does in includes/global/posttype.php: a gallery is a non-public `envira`
 * post behind its own capabilities (edit_envira_galleries and the rest).
 * Its data is the _eg_gallery_data meta (id, gallery: attachment id => item
 * in display order, config), its membership list the _eg_in_gallery meta,
 * and each image names its galleries in its own _eg_has_gallery meta
 * (includes/admin/ajax.php, includes/services/GalleryService.php). Its
 * rendered data is cached in _eg_cache_* transients.
 *
 * The writes are off until the site opts in (wpmcp_enable_envira_write) and
 * each is undone in a test.
 */
final class EnviraPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'envira-list-galleries' => 'read',
        'envira-get-gallery'    => 'read',
        'envira-create-gallery' => 'write',
        'envira-add-images'     => 'write',
        'envira-remove-images'  => 'write',
        'envira-reorder-images' => 'write',
    ];

    private const CAPS = [
        'edit_envira_gallery', 'read_envira_gallery', 'delete_envira_gallery', 'edit_envira_galleries', 'edit_other_envira_galleries',
        'edit_others_envira_galleries', 'publish_envira_galleries', 'read_private_envira_galleries', 'delete_envira_galleries',
        'edit_published_envira_galleries', 'edit_private_envira_galleries', 'create_envira_galleries',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        if (! post_type_exists('envira')) {
            register_post_type('envira', [
                'public'       => false,
                'show_ui'      => true,
                'supports'     => [ 'title' ],
                'map_meta_cap' => true,
                'capabilities' => [
                    'edit_post'              => 'edit_envira_gallery',
                    'read_post'              => 'read_envira_gallery',
                    'delete_post'            => 'delete_envira_gallery',
                    'edit_posts'             => 'edit_envira_galleries',
                    'edit_others_posts'      => 'edit_other_envira_galleries',
                    'publish_posts'          => 'publish_envira_galleries',
                    'read_private_posts'     => 'read_private_envira_galleries',
                    'edit_published_posts'   => 'edit_published_envira_galleries',
                    'edit_private_posts'     => 'edit_private_envira_galleries',
                    'create_posts'           => 'create_envira_galleries',
                    'delete_posts'           => 'delete_envira_galleries',
                    'delete_published_posts' => 'delete_envira_galleries',
                    'delete_others_posts'    => 'delete_envira_galleries',
                ],
            ]);
        }
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_envira_active', '__return_true');
        add_filter('wpmcp_enable_envira_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_envira_active');
        remove_all_filters('wpmcp_enable_envira_write');
        if (! defined('ENVIRA_LITE_VERSION')) {
            $role = get_role('administrator');
            foreach (self::CAPS as $cap) {
                $role->remove_cap($cap);
            }
            unregister_post_type('envira');
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

    /** A gallery as envira_gallery_ajax_insert_images() leaves it. */
    private function gallery(string $title = 'Summer', array $images = [], string $status = 'publish'): int
    {
        $id   = self::factory()->post->create([ 'post_type' => 'envira', 'post_title' => $title, 'post_status' => $status ]);
        $data = [ 'id' => $id, 'gallery' => [], 'config' => [ 'type' => 'default', 'columns' => '0', 'title' => $title, 'slug' => sanitize_title($title) ] ];
        foreach ($images as $image) {
            $data['gallery'][ $image ] = [ 'status' => 'active', 'src' => 'https://example.test/' . $image . '.jpg', 'title' => get_the_title($image), 'link' => '', 'alt' => '', 'caption' => '', 'thumb' => '' ];
            update_post_meta($image, '_eg_has_gallery', [ $id ]);
        }
        update_post_meta($id, '_eg_gallery_data', $data);
        update_post_meta($id, '_eg_in_gallery', $images);
        return $id;
    }

    private function ids(int $gallery): array
    {
        return array_map('intval', array_keys((array) (get_post_meta($gallery, '_eg_gallery_data', true)['gallery'] ?? [])));
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

    public function test_while_envira_is_missing_a_call_answers_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_envira_active');
        add_filter('wpmcp_envira_active', '__return_false');
        $out = $this->read('envira-list-galleries');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Envira Gallery', $out['error']['message']);
    }

    public function test_presence_defaults_to_envira_running(): void
    {
        remove_all_filters('wpmcp_envira_active');
        $this->assertSame(class_exists('Envira_Gallery_Common') || class_exists('Envira_Gallery'), Envira_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $img = $this->image();
        $id  = $this->gallery('Summer', [ $img ]);
        remove_all_filters('wpmcp_enable_envira_write');
        foreach ([
            [ 'envira-create-gallery', [ 'title' => 'x' ] ],
            [ 'envira-add-images', [ 'id' => $id, 'image_ids' => [ $this->image('Other') ] ] ],
            [ 'envira-remove-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
            [ 'envira-reorder-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_envira_write', $out['error']['message']);
        }
        $this->assertSame([ $img ], $this->ids($id));
    }

    public function test_only_users_with_the_envira_capability_may_read_or_write(): void
    {
        $img = $this->image();
        $id  = $this->gallery('Summer', [ $img ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('envira-list-galleries')['error']['code'] ?? null, 'an editor without Envira\'s capabilities');
        $this->assertSame('operation_denied', $this->write('envira-create-gallery', [ 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame([ $img ], $this->ids($id));
    }

    public function test_list_and_get_return_galleries_and_their_images_in_order(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $a   = $this->gallery('Alpha', [ $two, $one ]);
        $b   = $this->gallery('Beta', [], 'draft');

        $out  = $this->ok($this->read('envira-list-galleries'));
        $rows = array_column($out['galleries'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame(2, $rows[ $a ]['image_count']);
        $this->assertSame('[envira-gallery id="' . $a . '"]', $rows[ $a ]['shortcode']);
        $this->assertSame([ $b ], array_column($this->read('envira-list-galleries', [ 'status' => 'draft' ])['galleries'], 'id'));

        $g = $this->ok($this->read('envira-get-gallery', [ 'id' => $a ]))['gallery'];
        $this->assertSame([ $two, $one ], $g['image_ids']);
        $this->assertSame('One', $g['images'][1]['title']);
        $this->assertSame('active', $g['images'][1]['status']);
        $this->assertSame('gallery_not_found', $this->read('envira-get-gallery', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_create_gallery_stores_its_data_and_memberships_and_one_session_undoes_both(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $out = $this->ok($this->write('envira-create-gallery', [ 'title' => 'Wedding', 'image_ids' => [ $two, $one, $two ] ]));
        $id  = (int) $out['result']['gallery']['id'];
        $this->assertSame('envira', get_post_type($id));
        $this->assertSame('draft', get_post_status($id));
        $data = get_post_meta($id, '_eg_gallery_data', true);
        $this->assertSame($id, $data['id']);
        $this->assertSame('Wedding', $data['config']['title']);
        $this->assertSame([ $two, $one ], $this->ids($id), 'duplicates dropped, order kept');
        $this->assertSame('active', $data['gallery'][ $one ]['status']);
        $this->assertSame((string) wp_get_attachment_url($one), $data['gallery'][ $one ]['src']);
        $this->assertSame([ $two, $one ], array_map('intval', get_post_meta($id, '_eg_in_gallery', true)));
        $this->assertSame([ $id ], array_map('intval', get_post_meta($one, '_eg_has_gallery', true)));

        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame('inactive', get_post_meta($id, '_eg_gallery_data', true)['status'] ?? null, 'Envira hides a trashed gallery');
        $this->assertSame('', get_post_meta($one, '_eg_has_gallery', true), 'the image no longer names the gallery');
        wp_untrash_post($id);
        $this->assertArrayNotHasKey('status', get_post_meta($id, '_eg_gallery_data', true));
    }

    public function test_add_images_updates_all_three_lists_flushes_the_cache_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one ]);
        set_transient('_eg_cache_id_' . $id, 'cached');

        $out = $this->ok($this->write('envira-add-images', [ 'id' => $id, 'image_ids' => [ $one, $two ] ]));
        $this->assertSame([ $one, $two ], $this->ids($id));
        $this->assertSame([ $one, $two ], array_map('intval', get_post_meta($id, '_eg_in_gallery', true)));
        $this->assertSame([ $id ], array_map('intval', get_post_meta($two, '_eg_has_gallery', true)));
        $this->assertFalse(get_transient('_eg_cache_id_' . $id), 'Envira\'s cache is flushed');

        set_transient('_eg_cache_id_' . $id, 'cached');
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $one ], $this->ids($id));
        $this->assertSame([ $one ], array_map('intval', get_post_meta($id, '_eg_in_gallery', true)));
        $this->assertSame('', get_post_meta($two, '_eg_has_gallery', true));
        $this->assertFalse(get_transient('_eg_cache_id_' . $id), 'the undo flushes Envira\'s cache too');
    }

    public function test_remove_images_drops_them_everywhere_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one, $two ]);

        $out = $this->ok($this->write('envira-remove-images', [ 'id' => $id, 'image_ids' => [ $one ] ]));
        $this->assertSame([ $two ], $this->ids($id));
        $this->assertSame([ $two ], array_map('intval', get_post_meta($id, '_eg_in_gallery', true)));
        $this->assertSame([], array_map('intval', (array) get_post_meta($one, '_eg_has_gallery', true)));
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $one, $two ], $this->ids($id));
        $this->assertSame([ $id ], array_map('intval', get_post_meta($one, '_eg_has_gallery', true)));
    }

    public function test_reorder_images_keeps_each_item_and_needs_exactly_the_current_images(): void
    {
        $one   = $this->image('One');
        $two   = $this->image('Two');
        $three = $this->image('Three');
        $id    = $this->gallery('Alpha', [ $one, $two, $three ]);

        $this->assertSame('images_mismatch', $this->write('envira-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one ] ])['error']['code'] ?? null);
        $out = $this->ok($this->write('envira-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one, $two ] ]));
        $this->assertSame([ $three, $one, $two ], $this->ids($id));
        $this->assertSame('https://example.test/' . $three . '.jpg', get_post_meta($id, '_eg_gallery_data', true)['gallery'][ $three ]['src'], 'items move whole');
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one, $two, $three ], $this->ids($id));
    }
}
