<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Product_Feed_Pack;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Product Feed Pro ops (issue #1096) against the real Product Feed Pro
 * for WooCommerce from wordpress.org and the real WooCommerce
 * (bin/test-local.sh --live-woo-addons): a feed saved through the plugin's
 * own Product_Feed factory is read back with its channel resolved by the
 * plugin, and a refresh queues the action the plugin's own scheduler runs.
 *
 * @group woo-addons-live
 */
class ProductFeedLiveTest extends \WP_UnitTestCase
{
    private int $feed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('WPMCP_LIVE_WOO_ADDONS') || ! class_exists('\AdTribes\PFP\Factories\Product_Feed')) {
            $this->markTestSkipped('Needs the live WooCommerce add-ons job (bin/test-local.sh --live-woo-addons).');
        }
        Snapshot_Store::install();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($admin)->add_cap('manage_woocommerce');
        wp_set_current_user($admin);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        add_filter('wpmcp_enable_product_feed_write', '__return_true');

        $feed               = new \AdTribes\PFP\Factories\Product_Feed();
        $feed->title        = 'Live Google feed';
        $feed->post_status  = 'publish';
        $feed->status       = 'not run yet';
        $feed->channel_hash = 'd4cb75caae5a73ac6b01171816b33bfb';
        $feed->file_name    = 'livefeed' . wp_rand(1000, 9999);
        $feed->file_format  = 'xml';
        $feed->save();
        $this->feed = (int) $feed->id;
        $this->assertGreaterThan(0, $this->feed);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_product_feed_write');
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('adt_pfp_as_generate_product_feed');
        }
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args = []): array
    {
        $integration = new Plugin_Data_Integration();
        return 'read' === $half
            ? $integration->handle_read([ 'operation' => $op, 'args' => $args ])
            : $integration->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    public function test_a_feed_saved_by_the_plugin_reads_back(): void
    {
        $this->assertTrue(Product_Feed_Pack::active());
        $out = $this->call('read', 'productfeed-get-feed', [ 'feed_id' => $this->feed ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $feed = $out['result']['feed'];
        $this->assertSame('Live Google feed', $feed['title']);
        $this->assertTrue($feed['active']);
        $this->assertSame('not run yet', $feed['status']);
        $this->assertSame('Google Shopping', $feed['channel'], 'the channel is resolved through the plugin');
        $this->assertStringEndsWith('.xml', $feed['file_url']);
        $this->assertNull($feed['last_run']);

        $this->assertContains($this->feed, array_column($this->call('read', 'productfeed-list-feeds')['result']['feeds'], 'id'));
    }

    public function test_a_product_excluded_in_the_plugin_is_listed(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Live excluded product');
        $product->set_sku('LIVE-EX-1');
        $product->update_meta_data('_woosea_exclude_product', 'yes');
        $id = $product->save();

        $out = $this->call('read', 'productfeed-list-excluded-products');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertContains($id, array_column($out['result']['products'], 'id'));
    }

    public function test_a_refresh_queues_the_plugins_own_generation(): void
    {
        $this->assertNotFalse(has_action('adt_pfp_as_generate_product_feed'), 'the plugin listens on the refresh hook');
        $preview = $this->call('write', 'productfeed-refresh-feed', [ 'feed_id' => $this->feed ]);
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null, (string) wp_json_encode($preview));

        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'productfeed-refresh-feed', 'args' => [ 'feed_id' => $this->feed ], 'confirm' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertFalse($out['recoverable']);
        $this->assertNotFalse(as_next_scheduled_action('adt_pfp_as_generate_product_feed', [ 'feed_id' => $this->feed ]));
    }
}
