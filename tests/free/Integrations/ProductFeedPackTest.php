<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Product_Feed_Pack;
use WPMCP\Safety\Snapshot_Store;

/**
 * Product Feed Pro for WooCommerce (issue #1096, wordpress.org slug
 * woo-product-feed-pro) on the plugin-data pair, against its storage as of
 * 13.5.x: a feed is an adt_product_feed post whose state is in adt_* post
 * meta, a product is excluded by its _woosea_exclude_product meta, and a
 * refresh is the plugin's own adt_pfp_as_generate_product_feed Action
 * Scheduler action.
 */
class ProductFeedPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'productfeed-list-feeds'             => 'read',
        'productfeed-get-feed'               => 'read',
        'productfeed-list-excluded-products' => 'read',
        'productfeed-refresh-feed'           => 'destructive',
    ];

    private int $feed = 0;

    private int $draft = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('as_schedule_single_action') || ! function_exists('wc_get_product')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Snapshot_Store::install();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        // WooCommerce grants this to administrators on install, which the test install skips.
        get_userdata($admin)->add_cap('manage_woocommerce');
        wp_set_current_user($admin);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        add_filter('wpmcp_product_feed_active', '__return_true');
        add_filter('wpmcp_enable_product_feed_write', '__return_true');
        register_post_type('adt_product_feed', [ 'public' => false ]);

        $this->feed  = $this->make_feed('Google Shopping', 'publish', [
            'status'              => 'ready',
            'file_name'           => 'abc123',
            'file_format'         => 'xml',
            'products_count'      => 12,
            'refresh_interval'    => 'daily',
            'last_updated'        => '20 Nov 2026 08:15:00',
            'history_products'    => [ [ '20 Nov 2026 08:15:00' => 9 ] ],
            'last_run_exclusions' => [ 'products_count' => 12, 'date' => '20 Nov 2026 08:15:00', 'counts' => [ 'manual' => 2, 'not_sold' => 1, 'walked' => 12 ] ],
        ]);
        $this->draft = $this->make_feed('Paused feed', 'draft', [ 'status' => 'stopped' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_product_feed_active');
        remove_all_filters('wpmcp_enable_product_feed_write');
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('adt_pfp_as_generate_product_feed');
        }
        unregister_post_type('adt_product_feed');
        parent::tearDown();
    }

    /** A feed as Product Feed Pro's Product_Feed::save() writes it. */
    private function make_feed(string $title, string $status, array $meta): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'adt_product_feed', 'post_title' => $title, 'post_status' => $status ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, 'adt_' . $key, $value);
        }
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
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
        remove_all_filters('wpmcp_product_feed_active');
        add_filter('wpmcp_product_feed_active', '__return_false');
        $out = $this->read('productfeed-list-feeds');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Product Feed Pro', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_product_feed_active');
        $this->assertSame(defined('WOOCOMMERCESEA_PLUGIN_VERSION'), Product_Feed_Pack::active());
    }

    public function test_list_feeds_reports_status_last_run_and_counts(): void
    {
        $out   = $this->read('productfeed-list-feeds');
        $feeds = array_column($out['feeds'], null, 'id');
        $this->assertCount(2, $feeds, (string) wp_json_encode($out));
        $feed = $feeds[ $this->feed ];
        $this->assertSame('Google Shopping', $feed['title']);
        $this->assertTrue($feed['active']);
        $this->assertSame('ready', $feed['status']);
        $this->assertSame('2026-11-20T08:15:00+00:00', $feed['last_run']);
        $this->assertSame(12, $feed['products_count']);
        $this->assertSame(9, $feed['products_in_feed']);
        $this->assertSame(3, $feed['excluded_last_run']);
        $this->assertSame('daily', $feed['refresh_interval']);
        $this->assertFalse($feed['error']);
        $this->assertFalse($feeds[ $this->draft ]['active']);
        $this->assertNull($feeds[ $this->draft ]['last_run']);

        $this->assertSame([ $this->draft ], array_column($this->read('productfeed-list-feeds', [ 'active' => false ])['feeds'], 'id'));
    }

    public function test_get_feed_breaks_down_the_last_run_exclusions(): void
    {
        $feed = $this->read('productfeed-get-feed', [ 'feed_id' => $this->feed ])['feed'];
        $reasons = array_column($feed['exclusions'], 'count', 'reason');
        $this->assertSame([ 'manual' => 2, 'not_sold' => 1 ], $reasons);
        $this->assertSame('xml', $feed['file_format']);

        $this->assertSame('feed_not_found', $this->read('productfeed-get-feed', [ 'feed_id' => 999999 ])['error']['code'] ?? null);
        $post = self::factory()->post->create();
        $this->assertSame('feed_not_found', $this->read('productfeed-get-feed', [ 'feed_id' => $post ])['error']['code'] ?? null, 'a plain post is not a feed');
    }

    public function test_list_excluded_products_reads_the_exclude_flag(): void
    {
        $excluded = self::factory()->post->create([ 'post_type' => 'product', 'post_title' => 'Hidden hoodie' ]);
        update_post_meta($excluded, '_woosea_exclude_product', 'yes');
        update_post_meta($excluded, '_sku', 'HOOD-1');
        $kept = self::factory()->post->create([ 'post_type' => 'product', 'post_title' => 'Shown shirt' ]);
        update_post_meta($kept, '_woosea_exclude_product', 'no');

        $out = $this->read('productfeed-list-excluded-products');
        $this->assertSame(1, $out['total'], (string) wp_json_encode($out));
        $this->assertSame($excluded, $out['products'][0]['id']);
        $this->assertSame('Hidden hoodie', $out['products'][0]['name']);
        $this->assertSame('HOOD-1', $out['products'][0]['sku']);
    }

    public function test_refresh_is_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_product_feed_write');
        $out = $this->write('productfeed-refresh-feed', [ 'feed_id' => $this->feed ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_product_feed_write', $out['error']['message']);
    }

    public function test_refresh_needs_confirm_in_the_arguments_and_cannot_be_undone(): void
    {
        $preview = $this->write('productfeed-refresh-feed', [ 'feed_id' => $this->feed ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null, (string) wp_json_encode($preview));
        $this->assertStringContainsString('Google Shopping', $preview['error']['message']);
        $this->assertFalse(as_next_scheduled_action('adt_pfp_as_generate_product_feed', [ 'feed_id' => $this->feed ]));

        $out = $this->write('productfeed-refresh-feed', [ 'feed_id' => $this->feed ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertTrue($out['result']['scheduled']);
        $this->assertNotFalse(as_next_scheduled_action('adt_pfp_as_generate_product_feed', [ 'feed_id' => $this->feed ]));
    }

    public function test_refresh_refuses_an_inactive_or_running_feed(): void
    {
        $this->assertSame('feed_inactive', $this->write('productfeed-refresh-feed', [ 'feed_id' => $this->draft ], true)['error']['code'] ?? null);
        update_post_meta($this->feed, 'adt_status', 'processing');
        $this->assertSame('feed_running', $this->write('productfeed-refresh-feed', [ 'feed_id' => $this->feed ], true)['error']['code'] ?? null);
        $this->assertFalse(as_next_scheduled_action('adt_pfp_as_generate_product_feed', [ 'feed_id' => $this->feed ]));
    }
}
