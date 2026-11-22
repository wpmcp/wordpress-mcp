<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\MetaSlider_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * MetaSlider (issue #705): free-tier ops on the plugin-data dispatcher pair.
 *
 * MetaSlider is not loaded in the shared test core (the live leg,
 * MetaSliderLiveTest, runs these ops against the real plugin). Presence is
 * driven through wpmcp_metaslider_active, and the storage is registered here
 * the way MetaSlider 3.114 does in ml-slider.php: a slider is a non-public
 * `ml-slider` post with its settings in the ml-slider_settings meta; each
 * slide is a non-public `ml-slide` post tagged with the `ml-slider` term
 * named after the slider id, ordered by menu_order, its image in
 * _thumbnail_id, its caption in post_excerpt and its link, title and window
 * in ml-slider_* meta (inc/slide/metaslide.image.class.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_metaslider_write)
 * and each is undone in a test.
 */
final class MetaSliderPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'metaslider-list-sliders'   => 'read',
        'metaslider-get-slider'     => 'read',
        'metaslider-create-slider'  => 'write',
        'metaslider-add-slides'     => 'write',
        'metaslider-reorder-slides' => 'write',
        'metaslider-update-slide'   => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        if (! post_type_exists('ml-slider')) {
            // MetaSlider maps every primitive capability to its one filtered capability.
            $caps = array_fill_keys([ 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'create_posts' ], 'edit_others_posts');
            $args = [ 'public' => false, 'show_ui' => false, 'map_meta_cap' => true, 'capabilities' => $caps ];
            register_post_type('ml-slider', $args + [ 'capability_type' => 'ml-slider' ]);
            register_post_type('ml-slide', $args + [ 'capability_type' => 'ml-slide', 'supports' => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt' ] ]);
            register_taxonomy('ml-slider', [ 'attachment', 'ml-slide' ], [ 'hierarchical' => true, 'public' => false, 'query_var' => false, 'rewrite' => false ]);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_metaslider_active', '__return_true');
        add_filter('wpmcp_enable_metaslider_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_metaslider_active');
        remove_all_filters('wpmcp_enable_metaslider_write');
        remove_all_filters('metaslider_capability');
        if (! defined('METASLIDER_VERSION')) {
            unregister_taxonomy('ml-slider');
            unregister_post_type('ml-slide');
            unregister_post_type('ml-slider');
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

    /** A slider as MetaSlider_Slideshows::create() stores it. */
    private function slider(string $title = 'Home'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'ml-slider', 'post_title' => $title, 'post_status' => 'publish' ]);
        add_post_meta($id, 'ml-slider_settings', [ 'type' => 'flex', 'width' => 700, 'height' => 300, 'title' => $title ], true);
        wp_insert_term((string) $id, 'ml-slider');
        return $id;
    }

    /** An image slide as MetaImageSlide::add_slide() stores it. */
    private function slide(int $slider, int $image, int $order): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'ml-slide', 'post_title' => "Slider {$slider} - image", 'post_status' => 'publish', 'menu_order' => $order, 'post_excerpt' => '', 'post_content' => '' ]);
        set_post_thumbnail($id, $image);
        update_post_meta($id, 'ml-slider_type', 'image');
        update_post_meta($id, 'ml-slider_inherit_image_title', true);
        update_post_meta($id, 'ml-slider_inherit_image_alt', true);
        $term = get_term_by('name', (string) $slider, 'ml-slider');
        wp_set_post_terms($id, [ (int) $term->term_id ], 'ml-slider', true);
        return $id;
    }

    private function slide_ids(int $slider): array
    {
        return array_column($this->ok($this->read('metaslider-get-slider', [ 'id' => $slider ]))['slider']['slides'], 'id');
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

    public function test_while_metaslider_is_missing_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_metaslider_active');
        add_filter('wpmcp_metaslider_active', '__return_false');
        $out = $this->read('metaslider-list-sliders');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate MetaSlider', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_metaslider_active');
        $this->assertSame(defined('METASLIDER_VERSION'), MetaSlider_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $slider = $this->slider();
        $slide  = $this->slide($slider, $this->image(), 1);
        remove_all_filters('wpmcp_enable_metaslider_write');
        foreach ([
            [ 'metaslider-create-slider', [ 'title' => 'x' ] ],
            [ 'metaslider-add-slides', [ 'id' => $slider, 'image_ids' => [ $this->image('Other') ] ] ],
            [ 'metaslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $slide ] ] ],
            [ 'metaslider-update-slide', [ 'slide_id' => $slide, 'caption' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_metaslider_write', $out['error']['message']);
        }
        $this->assertSame([ $slide ], $this->slide_ids($slider));
    }

    public function test_the_metaslider_capability_filter_is_honoured(): void
    {
        $slider = $this->slider();
        add_filter('metaslider_capability', static fn (): string => 'manage_network');
        $this->assertSame('operation_denied', $this->read('metaslider-list-sliders')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('metaslider-create-slider', [ 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('metaslider-get-slider', [ 'id' => $slider ])['error']['code'] ?? null);
    }

    public function test_list_and_get_return_sliders_and_slides_in_order(): void
    {
        $a      = $this->slider('Alpha');
        $b      = $this->slider('Beta');
        $one    = $this->image('One');
        $two    = $this->image('Two');
        $first  = $this->slide($a, $two, 1);
        $second = $this->slide($a, $one, 2);
        update_post_meta($one, '_wp_attachment_image_alt', 'One alt');
        wp_update_post([ 'ID' => $second, 'post_excerpt' => 'Second caption' ]);
        update_post_meta($second, 'ml-slider_url', 'https://example.test/');

        $out  = $this->ok($this->read('metaslider-list-sliders'));
        $rows = array_column($out['sliders'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame(2, $rows[ $a ]['slide_count']);
        $this->assertSame(0, $rows[ $b ]['slide_count']);
        $this->assertSame('flex', $rows[ $a ]['type']);
        $this->assertSame('[metaslider id="' . $a . '"]', $rows[ $a ]['shortcode']);
        $this->assertSame([ $a ], array_column($this->read('metaslider-list-sliders', [ 'search' => 'Alpha' ])['sliders'], 'id'));

        $s = $this->ok($this->read('metaslider-get-slider', [ 'id' => $a ]))['slider'];
        $this->assertSame([ $first, $second ], array_column($s['slides'], 'id'));
        $this->assertSame($one, $s['slides'][1]['image_id']);
        $this->assertSame('One', $s['slides'][1]['title'], 'an inherited title is the image title');
        $this->assertSame('One alt', $s['slides'][1]['alt']);
        $this->assertSame('Second caption', $s['slides'][1]['caption']);
        $this->assertSame('https://example.test/', $s['slides'][1]['url']);
        $this->assertFalse($s['slides'][1]['new_window']);
        $this->assertSame(700, $s['settings']['width']);
        $this->assertSame('slider_not_found', $this->read('metaslider-get-slider', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_create_slider_stores_it_with_its_slides_and_rollback_session_trashes_them(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $out = $this->ok($this->write('metaslider-create-slider', [ 'title' => 'Launch', 'image_ids' => [ $two, $one ], 'width' => 900 ]));
        $id  = (int) $out['result']['slider']['id'];
        $this->assertSame('ml-slider', get_post_type($id));
        $this->assertSame('publish', get_post_status($id), 'MetaSlider shows only published sliders');
        $settings = get_post_meta($id, 'ml-slider_settings', true);
        $this->assertSame('flex', $settings['type']);
        $this->assertSame(900, $settings['width']);
        $slides = $out['result']['slider']['slides'];
        $this->assertSame([ $two, $one ], array_column($slides, 'image_id'));
        foreach ($slides as $slide) {
            $this->assertSame('ml-slide', get_post_type($slide['id']));
            $this->assertSame('image', get_post_meta($slide['id'], 'ml-slider_type', true));
            $this->assertTrue(has_term((string) $id, 'ml-slider', $slide['id']));
        }

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame('trash', get_post_status($id));
        foreach ($slides as $slide) {
            $this->assertSame('trash', get_post_status($slide['id']));
        }
    }

    public function test_create_slider_refuses_an_id_that_is_not_an_image(): void
    {
        $out = $this->write('metaslider-create-slider', [ 'title' => 'x', 'image_ids' => [ self::factory()->post->create() ] ]);
        $this->assertSame('image_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(0, (int) (new \WP_Query([ 'post_type' => 'ml-slider', 'post_status' => 'any', 'fields' => 'ids' ]))->found_posts);
    }

    public function test_add_slides_appends_after_the_last_slide_and_rolls_back(): void
    {
        $slider = $this->slider();
        $old    = $this->slide($slider, $this->image('One'), 4);
        $two    = $this->image('Two');

        $out   = $this->ok($this->write('metaslider-add-slides', [ 'id' => $slider, 'image_ids' => [ $two ] ]));
        $ids   = $this->slide_ids($slider);
        $added = (int) $ids[1];
        $this->assertSame($old, $ids[0]);
        $this->assertSame(5, (int) get_post_field('menu_order', $added));
        $this->assertSame($two, (int) get_post_thumbnail_id($added));

        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $old ], $this->slide_ids($slider));
    }

    public function test_reorder_slides_needs_exactly_the_current_slides_and_rolls_back(): void
    {
        $slider = $this->slider();
        $a      = $this->slide($slider, $this->image('A'), 1);
        $b      = $this->slide($slider, $this->image('B'), 2);
        $c      = $this->slide($slider, $this->image('C'), 3);

        $this->assertSame('slides_mismatch', $this->write('metaslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a ] ])['error']['code'] ?? null);
        $this->assertSame('slides_mismatch', $this->write('metaslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a, $a ] ])['error']['code'] ?? null);

        $out = $this->ok($this->write('metaslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a, $b ] ]));
        $this->assertSame([ $c, $a, $b ], $this->slide_ids($slider));
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $a, $b, $c ], $this->slide_ids($slider));
    }

    public function test_update_slide_sets_caption_link_title_and_alt_and_rolls_back(): void
    {
        $slider = $this->slider();
        $image  = $this->image('Pier');
        $slide  = $this->slide($slider, $image, 1);

        $out = $this->ok($this->write('metaslider-update-slide', [ 'slide_id' => $slide, 'caption' => 'At dusk', 'url' => 'https://example.test/pier', 'new_window' => true, 'title' => 'The pier', 'alt' => 'A pier' ]));
        $this->assertTrue($out['recoverable']);
        $view = $out['result']['slide'];
        $this->assertSame('At dusk', $view['caption']);
        $this->assertSame('The pier', $view['title']);
        $this->assertSame('A pier', $view['alt']);
        $this->assertTrue($view['new_window']);
        $this->assertSame('https://example.test/pier', get_post_meta($slide, 'ml-slider_url', true));
        $this->assertFalse((bool) get_post_meta($slide, 'ml-slider_inherit_image_title', true));
        $this->assertSame('', (string) get_post_meta($image, '_wp_attachment_image_alt', true), 'the image itself is left alone');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('', get_post_field('post_excerpt', $slide));
        $this->assertSame('', get_post_meta($slide, 'ml-slider_url', true));
        $this->assertTrue((bool) get_post_meta($slide, 'ml-slider_inherit_image_title', true));
    }

    public function test_update_slide_refuses_a_post_that_is_not_a_slide(): void
    {
        $out = $this->write('metaslider-update-slide', [ 'slide_id' => self::factory()->post->create(), 'caption' => 'x' ]);
        $this->assertSame('slide_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }
}
