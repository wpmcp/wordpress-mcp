<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\SmartSlider_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Smart Slider 3 (issue #705): free-tier ops on the plugin-data pair.
 *
 * Smart Slider 3 is not loaded in the shared test core (SmartSliderLiveTest
 * runs the ops against the real plugin). Presence is driven through
 * wpmcp_smartslider_active, and its two tables are built here column for
 * column as Smart Slider 3.5 installs them (Nextend/SmartSlider3/Install/Tables.php):
 * nextend2_smartslider3_sliders (id, title, type, params JSON,
 * slider_status) and nextend2_smartslider3_slides (one row per slide, its
 * slider in `slider`, display order in `ordering`, title, description,
 * published, params JSON with the background image).
 *
 * The writes are off until the site opts in (wpmcp_enable_smartslider_write)
 * and each is undone in a test.
 */
final class SmartSliderPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'smartslider-list-sliders'  => 'read',
        'smartslider-get-slider'    => 'read',
        'smartslider-reorder-slides' => 'write',
        'smartslider-update-slide'  => 'write',
    ];

    private const CAPS = [ 'smartslider', 'smartslider_edit' ];

    /** DDL commits implicitly, so the tables are built once for the class. */
    public static function wpSetUpBeforeClass(): void
    {
        if (! defined('NEXTEND_SMARTSLIDER_3')) {
            self::tables();
        }
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        if (! defined('NEXTEND_SMARTSLIDER_3')) {
            foreach ([ 'nextend2_smartslider3_sliders', 'nextend2_smartslider3_slides' ] as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_smartslider_active', '__return_true');
        add_filter('wpmcp_enable_smartslider_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_smartslider_active');
        remove_all_filters('wpmcp_enable_smartslider_write');
        if (! defined('NEXTEND_SMARTSLIDER_3')) {
            $role = get_role('administrator');
            foreach (self::CAPS as $cap) {
                $role->remove_cap($cap);
            }
        }
        parent::tearDown();
    }

    private static function tables(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ([ 'nextend2_smartslider3_sliders', 'nextend2_smartslider3_slides' ] as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $p . $table));
        }
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}nextend2_smartslider3_sliders (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `alias` TEXT NULL DEFAULT NULL, `title` TEXT NOT NULL, `type` VARCHAR(30) NOT NULL,
            `params` MEDIUMTEXT NOT NULL, `slider_status` VARCHAR(50) NOT NULL DEFAULT 'published', `time` DATETIME NOT NULL,
            `thumbnail` TEXT NOT NULL, `ordering` INT NOT NULL DEFAULT '0', PRIMARY KEY (`id`))");
        $wpdb->query("CREATE TABLE {$p}nextend2_smartslider3_slides (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `title` TEXT NOT NULL, `slider` INT(11) NOT NULL,
            `publish_up` DATETIME NOT NULL default '1970-01-01 00:00:00', `publish_down` DATETIME NOT NULL default '1970-01-01 00:00:00',
            `published` TINYINT(1) NOT NULL, `first` INT(11) NOT NULL, `slide` LONGTEXT, `description` TEXT NOT NULL,
            `thumbnail` TEXT NOT NULL, `params` TEXT NOT NULL, `ordering` INT(11) NOT NULL, `generator_id` INT(11) NOT NULL,
            PRIMARY KEY (`id`), INDEX (`slider`))");
        // phpcs:enable
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

    /** A slider row as ModelSliders::create() inserts it. */
    private function slider(string $title = 'Home', string $status = 'published'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'nextend2_smartslider3_sliders', [ 'title' => $title, 'type' => 'simple', 'params' => '{"width":"1200","height":"600"}', 'slider_status' => $status, 'time' => '2026-01-01 00:00:00', 'thumbnail' => '', 'ordering' => 0 ]);
        return (int) $wpdb->insert_id;
    }

    /** A slide row as ModelSlides::createQuickImage() inserts it. */
    private function slide(int $slider, string $title, int $ordering, string $image = '$upload$/2026/01/pier.jpg'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'nextend2_smartslider3_slides', [
            'title' => $title, 'slider' => $slider, 'published' => 1, 'first' => 0, 'slide' => '[]', 'description' => '',
            'thumbnail' => $image, 'params' => wp_json_encode([ 'background-type' => 'image', 'backgroundImage' => $image ]), 'ordering' => $ordering, 'generator_id' => 0,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function order(int $slider): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE slider = %d ORDER BY ordering, id', $wpdb->prefix . 'nextend2_smartslider3_slides', $slider)));
    }

    private function row(int $slide): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'nextend2_smartslider3_slides', $slide), ARRAY_A);
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

    public function test_while_smart_slider_is_missing_a_call_answers_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_smartslider_active');
        add_filter('wpmcp_smartslider_active', '__return_false');
        $out = $this->read('smartslider-list-sliders');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Smart Slider 3', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_smartslider_active');
        $this->assertSame(defined('NEXTEND_SMARTSLIDER_3'), SmartSlider_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $slider = $this->slider();
        $slide  = $this->slide($slider, 'One', 1);
        remove_all_filters('wpmcp_enable_smartslider_write');
        foreach ([
            [ 'smartslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $slide ] ] ],
            [ 'smartslider-update-slide', [ 'slide_id' => $slide, 'title' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_smartslider_write', $out['error']['message']);
        }
        $this->assertSame('One', $this->row($slide)['title']);
    }

    public function test_smart_sliders_own_capabilities_are_required(): void
    {
        $slider = $this->slider();
        $slide  = $this->slide($slider, 'One', 1);
        $denied = [];
        // map_meta_cap, not user_has_cap: a super admin (the test user on a
        // network) passes every check before user_has_cap runs, but never
        // do_not_allow.
        $filter = static function (array $caps, string $cap) use (&$denied): array {
            return in_array($cap, $denied, true) || [] !== array_intersect($caps, $denied) ? [ 'do_not_allow' ] : $caps;
        };
        add_filter('map_meta_cap', $filter, 10, 2);
        try {
            foreach ([ 'smartslider_edit', 'unfiltered_html' ] as $cap) {
                $denied = [ $cap ];
                $this->assertSame('operation_denied', $this->write('smartslider-update-slide', [ 'slide_id' => $slide, 'title' => 'x' ])['error']['code'] ?? null, 'writes need ' . $cap);
            }
            $denied = [ 'smartslider' ];
            $this->assertSame('operation_denied', $this->read('smartslider-list-sliders')['error']['code'] ?? null, 'reads need smartslider');
        } finally {
            remove_filter('map_meta_cap', $filter, 10);
        }
        $this->assertSame('One', $this->row($slide)['title']);
    }

    public function test_list_and_get_return_sliders_and_slides_in_order(): void
    {
        $a = $this->slider('Alpha');
        $b = $this->slider('Beta');
        $this->slider('Gone', 'trash');
        $second = $this->slide($a, 'Second', 2);
        $first  = $this->slide($a, 'First', 1, 'https://cdn.example.test/first.jpg');

        $out  = $this->ok($this->read('smartslider-list-sliders'));
        $rows = array_column($out['sliders'], null, 'id');
        $this->assertSame([ $a, $b ], array_keys($rows), 'trashed sliders are left out');
        $this->assertSame(2, $rows[ $a ]['slide_count']);
        $this->assertSame('[smartslider3 slider="' . $a . '"]', $rows[ $a ]['shortcode']);
        $this->assertSame([ $a ], array_column($this->read('smartslider-list-sliders', [ 'search' => 'Alph' ])['sliders'], 'id'));

        $s = $this->ok($this->read('smartslider-get-slider', [ 'id' => $a ]))['slider'];
        $this->assertSame([ $first, $second ], array_column($s['slides'], 'id'));
        $this->assertSame('https://cdn.example.test/first.jpg', $s['slides'][0]['image']);
        $this->assertSame(wp_upload_dir()['baseurl'] . '/2026/01/pier.jpg', $s['slides'][1]['image'], 'the $upload$ keyword is resolved');
        $this->assertTrue($s['slides'][0]['published']);
        $this->assertSame('slider_not_found', $this->read('smartslider-get-slider', [ 'id' => 99999 ])['error']['code'] ?? null);
    }

    public function test_reorder_slides_needs_exactly_the_current_slides_and_rolls_back(): void
    {
        $slider = $this->slider();
        $a      = $this->slide($slider, 'A', 1);
        $b      = $this->slide($slider, 'B', 2);
        $c      = $this->slide($slider, 'C', 3);
        $other  = $this->slide($this->slider('Other'), 'X', 1);

        $this->assertSame('slides_mismatch', $this->write('smartslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a ] ])['error']['code'] ?? null);
        $this->assertSame('slides_mismatch', $this->write('smartslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a, $other ] ])['error']['code'] ?? null);

        $out = $this->ok($this->write('smartslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => [ $c, $a, $b ] ]));
        $this->assertSame([ $c, $a, $b ], $this->order($slider));
        $this->assertSame(1, (int) $this->row($c)['ordering'], 'ordering starts at 1, as ModelSlides::order() numbers it');
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $a, $b, $c ], $this->order($slider));
    }

    public function test_update_slide_changes_title_description_and_published_and_rolls_back(): void
    {
        $slider = $this->slider();
        $slide  = $this->slide($slider, 'One', 1);

        $out = $this->ok($this->write('smartslider-update-slide', [ 'slide_id' => $slide, 'title' => 'Opening', 'description' => 'At dawn', 'published' => false ]));
        $row = $this->row($slide);
        $this->assertSame('Opening', $row['title']);
        $this->assertSame('At dawn', $row['description']);
        $this->assertSame('0', (string) $row['published']);
        $this->assertFalse($out['result']['slide']['published']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $row = $this->row($slide);
        $this->assertSame('One', $row['title']);
        $this->assertSame('', $row['description']);
        $this->assertSame('1', (string) $row['published']);
        $this->assertSame('slide_not_found', $this->write('smartslider-update-slide', [ 'slide_id' => 99999, 'title' => 'x' ])['error']['code'] ?? null);
    }
}
