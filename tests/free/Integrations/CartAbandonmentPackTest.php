<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Cart_Abandonment_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/cart-abandonment-stubs.php';

/**
 * Cart Abandonment Recovery for WooCommerce (issue #1096, wordpress.org slug
 * woo-cart-abandonment-recovery) on the plugin-data pair, against its table
 * layout (tests/support/cart-abandonment-stubs.php): carts in
 * cartflows_ca_cart_abandonment, follow-up emails in
 * cartflows_ca_email_templates with their coupon settings in
 * cartflows_ca_email_templates_meta, the send queue in
 * cartflows_ca_email_history, settings in wcf_ca_* options.
 */
class CartAbandonmentPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'cartabandonment-get-settings'    => 'read',
        'cartabandonment-get-stats'       => 'read',
        'cartabandonment-list-carts'      => 'read',
        'cartabandonment-list-templates'  => 'read',
        'cartabandonment-get-template'    => 'read',
        'cartabandonment-update-template' => 'write',
        'cartabandonment-update-timing'   => 'write',
    ];

    private int $template = 0;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        wpmcp_test_create_cart_abandonment_tables();
    }

    public static function tear_down_after_class(): void
    {
        wpmcp_test_drop_cart_abandonment_tables();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        // WooCommerce grants this to administrators on install, which the test install skips.
        get_userdata($admin)->add_cap('manage_woocommerce');
        wp_set_current_user($admin);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        add_filter('wpmcp_cart_abandonment_active', '__return_true');
        add_filter('wpmcp_enable_cart_abandonment_write', '__return_true');

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert($p . 'cartflows_ca_email_templates', [
            'template_name' => 'Purchase issue?', 'email_subject' => 'Purchase issue?', 'email_body' => '<p>Hi {{customer.firstname}}</p>',
            'is_activated' => 1, 'frequency' => 30, 'frequency_unit' => 'MINUTE',
        ]);
        $this->template = (int) $wpdb->insert_id;
        $wpdb->insert($p . 'cartflows_ca_email_templates_meta', [ 'email_template_id' => $this->template, 'meta_key' => 'override_global_coupon', 'meta_value' => 'on' ]);
        $wpdb->insert($p . 'cartflows_ca_email_templates', [
            'template_name' => 'Need help?', 'email_subject' => 'Need help?', 'email_body' => '<p>Still there?</p>',
            'is_activated' => 0, 'frequency' => 1, 'frequency_unit' => 'DAY',
        ]);

        $today = gmdate('Y-m-d H:i:s');
        foreach ([
            [ 'ada@example.test', 'abandoned', '40.00', 's1' ],
            [ 'bob@example.test', 'abandoned', '10.50', 's2' ],
            [ 'cy@example.test', 'completed', '25.00', 's3' ],
            [ 'di@example.test', 'lost', '99.99', 's4' ],
        ] as [ $email, $status, $total, $session ]) {
            $wpdb->insert($p . 'cartflows_ca_cart_abandonment', [
                'checkout_id' => 1, 'email' => $email, 'cart_contents' => serialize([ 'k1' => [ 'product_id' => 5, 'quantity' => 2 ] ]),
                'cart_total' => $total, 'session_id' => $session, 'other_fields' => serialize([ 'wcf_first_name' => 'Ada', 'wcf_phone_number' => '555-0100' ]),
                'order_status' => $status, 'time' => $today,
            ]);
        }
        $wpdb->insert($p . 'cartflows_ca_email_history', [ 'template_id' => $this->template, 'ca_session_id' => 's1', 'scheduled_time' => $today, 'email_sent' => 1 ]);
        $wpdb->insert($p . 'cartflows_ca_email_history', [ 'template_id' => $this->template, 'ca_session_id' => 's2', 'scheduled_time' => $today, 'email_sent' => 0 ]);

        update_option('wcf_ca_status', 'on');
        update_option('wcf_ca_cron_run_time', 20);
        update_option('wcf_ca_zapier_cart_abandoned_webhook', 'https://hooks.zapier.test/hooks/catch/123/secret');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([ 'cartflows_ca_email_history', 'cartflows_ca_email_templates_meta', 'cartflows_ca_email_templates', 'cartflows_ca_cart_abandonment' ] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        remove_all_filters('wpmcp_cart_abandonment_active');
        remove_all_filters('wpmcp_enable_cart_abandonment_write');
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

    private function row(): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'cartflows_ca_email_templates', $this->template), ARRAY_A);
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
    }

    public function test_without_the_plugin_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_cart_abandonment_active');
        add_filter('wpmcp_cart_abandonment_active', '__return_false');
        $out = $this->read('cartabandonment-list-templates');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Cart Abandonment Recovery', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_cart_abandonment_active');
        $this->assertSame(defined('CARTFLOWS_CA_VER'), Cart_Abandonment_Pack::active());
    }

    public function test_settings_never_return_the_webhook_url(): void
    {
        $out = $this->read('cartabandonment-get-settings');
        $this->assertTrue($out['settings']['tracking_enabled']);
        $this->assertSame(20, $out['settings']['abandoned_after_minutes']);
        $this->assertSame(30, $out['settings']['lost_after_days'], 'the plugin default when unset');
        $this->assertTrue($out['settings']['zapier_webhook_set']);
        $this->assertStringNotContainsString('zapier.test', (string) wp_json_encode($out));
    }

    public function test_stats_count_recoverable_recovered_and_lost_carts(): void
    {
        $stats = $this->read('cartabandonment-get-stats');
        $this->assertSame(2, $stats['recoverable']['orders'], (string) wp_json_encode($stats));
        $this->assertEqualsWithDelta(50.5, $stats['recoverable']['revenue'], 0.001);
        $this->assertSame(1, $stats['recovered']['orders']);
        $this->assertEqualsWithDelta(25.0, $stats['recovered']['revenue'], 0.001);
        $this->assertSame(1, $stats['lost']['orders']);
        $this->assertEqualsWithDelta(99.99, $stats['lost']['revenue'], 0.001);
        $this->assertEqualsWithDelta(33.33, $stats['recovery_rate'], 0.01);

        $none = $this->read('cartabandonment-get-stats', [ 'start_date' => '2001-01-01', 'end_date' => '2001-01-31' ]);
        $this->assertSame(0, $none['recovered']['orders']);
        $this->assertSame('bad_date', $this->read('cartabandonment-get-stats', [ 'start_date' => '2026-13-45' ])['error']['code'] ?? null);
    }

    public function test_list_carts_masks_customer_emails_and_keeps_no_names_or_phones(): void
    {
        $out = $this->read('cartabandonment-list-carts', [ 'status' => 'abandoned' ]);
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['masked']);
        $json = (string) wp_json_encode($out);
        foreach ([ 'ada@example.test', 'bob@example.test', 'Ada', '555-0100' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        $this->assertContains('a***@example.test', array_column($out['carts'], 'email'));
        $this->assertSame(2, $out['carts'][0]['items']);
        $this->assertSame(1, array_column($out['carts'], 'emails_sent', 'email')['a***@example.test']);
    }

    public function test_templates_are_listed_with_their_delay_and_read_one_in_full(): void
    {
        $list = $this->read('cartabandonment-list-templates')['templates'];
        $this->assertCount(2, $list);
        $first = array_column($list, null, 'id')[ $this->template ];
        $this->assertTrue($first['active']);
        $this->assertSame(30, $first['delay']);
        $this->assertSame('MINUTE', $first['delay_unit']);
        $this->assertSame(30, $first['delay_minutes']);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $first['queued']);
        $this->assertSame(1440, array_column($list, 'delay_minutes', 'name')['Need help?']);

        $one = $this->read('cartabandonment-get-template', [ 'template_id' => $this->template ])['template'];
        $this->assertSame('<p>Hi {{customer.firstname}}</p>', $one['body']);
        $this->assertSame('on', $one['meta']['override_global_coupon']);
        $this->assertSame('template_not_found', $this->read('cartabandonment-get-template', [ 'template_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_cart_abandonment_write');
        foreach ([ [ 'cartabandonment-update-template', [ 'template_id' => $this->template, 'delay' => 45 ] ], [ 'cartabandonment-update-timing', [ 'abandoned_after_minutes' => 30 ] ] ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_cart_abandonment_write', $out['error']['message']);
        }
    }

    public function test_a_template_edit_is_sanitized_and_rolls_back(): void
    {
        $out = $this->write('cartabandonment-update-template', [
            'template_id' => $this->template,
            'subject'     => 'You left something behind',
            'body'        => '<p>Come back</p><script>alert(1)</script>',
            'delay'       => 2,
            'delay_unit'  => 'HOUR',
            'active'      => false,
        ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $row = $this->row();
        $this->assertSame('You left something behind', $row['email_subject']);
        $this->assertStringNotContainsString('<script>', $row['email_body']);
        $this->assertSame('2', (string) $row['frequency']);
        $this->assertSame('HOUR', $row['frequency_unit']);
        $this->assertSame('0', (string) $row['is_activated']);
        $this->assertSame(120, $out['result']['template']['delay_minutes']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $row = $this->row();
        $this->assertSame('Purchase issue?', $row['email_subject']);
        $this->assertSame('30', (string) $row['frequency']);
        $this->assertSame('MINUTE', $row['frequency_unit']);
        $this->assertSame('1', (string) $row['is_activated']);
    }

    public function test_a_template_edit_refuses_no_change_and_unknown_templates(): void
    {
        $this->assertSame('no_change', $this->write('cartabandonment-update-template', [ 'template_id' => $this->template, 'delay' => 30 ])['error']['code'] ?? null);
        $this->assertSame('template_not_found', $this->write('cartabandonment-update-template', [ 'template_id' => 999999, 'delay' => 5 ])['error']['code'] ?? null);
    }

    public function test_timing_respects_the_plugins_minimums_and_rolls_back(): void
    {
        $low = $this->write('cartabandonment-update-timing', [ 'abandoned_after_minutes' => 5 ]);
        $this->assertNotEmpty($low['error'] ?? null, 'below the plugin\'s 10 minute minimum');

        $out = $this->write('cartabandonment-update-timing', [ 'abandoned_after_minutes' => 45, 'lost_after_days' => 14 ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame(45, (int) get_option('wcf_ca_cron_run_time'));
        $this->assertSame(14, (int) get_option('wcf_ca_cart_lost_time'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(20, (int) get_option('wcf_ca_cron_run_time'));
        $this->assertFalse(get_option('wcf_ca_cart_lost_time'));
    }
}
