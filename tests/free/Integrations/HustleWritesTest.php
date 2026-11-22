<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Hustle_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1091: Hustle writes on the plugin-data pair, beside the reads of
 * #898. Hustle 7 keeps a module as a hustle_modules row (active switch) and
 * its data as JSON rows of hustle_modules_meta (content, settings with the
 * schedule, visibility), caches both in the object cache (groups
 * hustle_model_data and hustle_module_meta, keyed by module id) and keeps
 * derived schedule_flags beside the settings, cleared when the settings
 * change (Hustle_Module_Model::update_module_metas()).
 */
class HustleWritesTest extends \WP_UnitTestCase
{
    private const TABLES = [ 'hustle_modules', 'hustle_modules_meta' ];

    private int $module = 0;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::drop();
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}hustle_modules (module_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, blog_id bigint(20) UNSIGNED NOT NULL DEFAULT '0', module_name varchar(255) NOT NULL, module_type varchar(100) NOT NULL, active tinyint DEFAULT 1, module_mode varchar(100) NOT NULL, PRIMARY KEY (module_id), KEY active (active))");
        $wpdb->query("CREATE TABLE {$p}hustle_modules_meta (meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, module_id bigint(20) UNSIGNED NOT NULL DEFAULT '0', meta_key varchar(191) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY module_id (module_id), KEY meta_key (meta_key))");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop();
    }

    private static function drop(): void
    {
        global $wpdb;
        foreach (self::TABLES as $t) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $t));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Snapshot_Store::install();
        foreach (self::TABLES as $t) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . $t));
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_hustle_active', '__return_true');
        add_filter('wpmcp_enable_hustle_write', '__return_true');

        $wpdb->insert($wpdb->prefix . 'hustle_modules', [ 'blog_id' => 0, 'module_name' => 'Spring', 'module_type' => 'popup', 'active' => 1, 'module_mode' => 'informational' ]);
        $this->module = (int) $wpdb->insert_id;
        $metas = [
            'content'        => [ 'module_name' => 'Spring', 'title' => 'Spring sale', 'sub_title' => 'Limited time', 'main_content' => '<p>Use code SAVE10</p>', 'cta_label' => 'Shop' ],
            'settings'       => [
                'triggers'    => [ 'trigger' => [ 'time' ] ],
                'is_schedule' => '0',
                'schedule'    => [ 'not_schedule_start' => '1', 'start_date' => '11/20/2026', 'start_hour' => '12', 'start_minute' => '00', 'start_meridiem_offset' => 'am', 'not_schedule_end' => '1', 'end_date' => '11/30/2026', 'end_hour' => '11', 'end_minute' => '59', 'end_meridiem_offset' => 'pm', 'time_to_use' => 'server' ],
            ],
            'visibility'     => [ 'conditions' => [] ],
            'schedule_flags' => [ 'is_currently_scheduled' => '1', 'check_schedule_at' => '0' ],
        ];
        foreach ($metas as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'hustle_modules_meta', [ 'module_id' => $this->module, 'meta_key' => $key, 'meta_value' => wp_json_encode($value) ]);
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_hustle_active');
        remove_all_filters('wpmcp_enable_hustle_write');
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function meta(string $key): array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM %i WHERE module_id = %d AND meta_key = %s', $wpdb->prefix . 'hustle_modules_meta', $this->module, $key));
        return (array) json_decode((string) $raw, true);
    }

    private function active(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT active FROM %i WHERE module_id = %d', $wpdb->prefix . 'hustle_modules', $this->module));
    }

    /** @param array<string, mixed> $args */
    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @param array<string, mixed> $out */
    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable'] ?? false, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_writes_are_catalogued_and_off_until_the_site_opts_in(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'hustle-update-content', 'hustle-set-schedule', 'hustle-set-active', 'hustle-set-display-conditions' ] as $op) {
            $this->assertSame('write', $ops[ $op ]['mode'] ?? null, $op);
        }
        remove_all_filters('wpmcp_enable_hustle_write');
        $out = $this->write('hustle-set-active', [ 'module_id' => $this->module, 'active' => false ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame(1, $this->active());
    }

    public function test_content_changes_only_the_named_fields_drop_the_cache_and_roll_back(): void
    {
        wp_cache_set($this->module, 'cached', 'hustle_module_meta');
        $out = $this->ok($this->write('hustle-update-content', [ 'module_id' => $this->module, 'content' => [ 'title' => 'Winter sale', 'cta_label' => 'Buy now' ] ]));
        $this->assertSame('Winter sale', $this->meta('content')['title']);
        $this->assertSame('Limited time', $this->meta('content')['sub_title'], 'untouched fields stay');
        $this->assertFalse(wp_cache_get($this->module, 'hustle_module_meta'), 'the meta cache is dropped');

        wp_cache_set($this->module, 'cached', 'hustle_module_meta');
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Spring sale', $this->meta('content')['title']);
        $this->assertSame('Shop', $this->meta('content')['cta_label']);
        $this->assertFalse(wp_cache_get($this->module, 'hustle_module_meta'), 'the undo drops the cache too');
    }

    public function test_content_refuses_unknown_fields(): void
    {
        $out = $this->write('hustle-update-content', [ 'module_id' => $this->module, 'content' => [ 'emails' => 'x' ] ]);
        $this->assertSame('invalid_setting', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_schedule_is_stored_in_hustles_format_resets_the_flags_and_rolls_back(): void
    {
        $out = $this->ok($this->write('hustle-set-schedule', [ 'module_id' => $this->module, 'enabled' => true, 'start' => '2026-12-01 09:30', 'end' => '2026-12-24 18:00' ]));
        $settings = $this->meta('settings');
        $this->assertSame('1', $settings['is_schedule']);
        $this->assertSame('12/01/2026', $settings['schedule']['start_date']);
        $this->assertSame('09', $settings['schedule']['start_hour']);
        $this->assertSame('30', $settings['schedule']['start_minute']);
        $this->assertSame('am', $settings['schedule']['start_meridiem_offset']);
        $this->assertSame('0', $settings['schedule']['not_schedule_start']);
        $this->assertSame('06', $settings['schedule']['end_hour']);
        $this->assertSame('pm', $settings['schedule']['end_meridiem_offset']);
        $this->assertSame([ 'time' ], $settings['triggers']['trigger'], 'the rest of the settings stays');
        $this->assertSame([], $this->meta('schedule_flags'), 'the derived flags are reset so Hustle checks the schedule again');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('0', $this->meta('settings')['is_schedule']);
        $this->assertSame('11/20/2026', $this->meta('settings')['schedule']['start_date']);
        $this->assertSame([], $this->meta('schedule_flags'), 'after the undo Hustle checks the schedule again too');
    }

    public function test_schedule_refuses_an_end_before_the_start(): void
    {
        $out = $this->write('hustle-set-schedule', [ 'module_id' => $this->module, 'enabled' => true, 'start' => '2026-12-24 09:00', 'end' => '2026-12-01 09:00' ]);
        $this->assertSame('invalid_setting', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_active_switch_drops_the_module_cache_and_rolls_back(): void
    {
        wp_cache_set($this->module, 'cached', 'hustle_model_data');
        $out = $this->ok($this->write('hustle-set-active', [ 'module_id' => $this->module, 'active' => false ]));
        $this->assertSame(0, $this->active());
        $this->assertFalse(wp_cache_get($this->module, 'hustle_model_data'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(1, $this->active());
    }

    public function test_display_conditions_replace_the_visibility_and_roll_back(): void
    {
        $visibility = [ 'conditions' => [ 'group-1' => [ 'show_or_hide_conditions' => 'show', 'pages' => [ 'filter_type' => 'only', 'pages' => [ '12' ] ] ] ] ];
        $out        = $this->ok($this->write('hustle-set-display-conditions', [ 'module_id' => $this->module, 'visibility' => $visibility ]));
        $this->assertSame($visibility, $this->meta('visibility'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'conditions' => [] ], $this->meta('visibility'));
    }

    public function test_an_unknown_module_is_refused(): void
    {
        $out = $this->write('hustle-set-active', [ 'module_id' => 999999, 'active' => true ]);
        $this->assertSame('module_not_found', $out['error']['code'] ?? null);
        $this->assertTrue(Hustle_Pack::active());
    }
}
