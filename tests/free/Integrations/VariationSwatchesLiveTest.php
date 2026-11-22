<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Variation_Swatches_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Variation Swatches ops (issue #1096) against the real Variation
 * Swatches for WooCommerce from wordpress.org and the real WooCommerce
 * (bin/test-local.sh --live-woo-addons): the types on offer come from the
 * plugin's own product_attributes_type_selector filter, and the plugin's
 * frontend reads the type the op wrote.
 *
 * @group woo-addons-live
 */
class VariationSwatchesLiveTest extends \WP_UnitTestCase
{
    private int $attribute = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('WPMCP_LIVE_WOO_ADDONS') || ! defined('WOO_VARIATION_SWATCHES_PLUGIN_VERSION') || ! function_exists('woo_variation_swatches')) {
            $this->markTestSkipped('Needs the live WooCommerce add-ons job (bin/test-local.sh --live-woo-addons).');
        }
        Snapshot_Store::install();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($admin)->add_cap('manage_product_terms');
        wp_set_current_user($admin);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        add_filter('wpmcp_enable_variation_swatches_write', '__return_true');

        $id = wc_create_attribute([ 'name' => 'Size', 'slug' => 'live-size', 'order_by' => 'name' ]);
        $this->assertIsInt($id);
        $this->attribute = $id;
        register_taxonomy('pa_live-size', [ 'product' ]);
    }

    /** Whether the plugin's own (transient-cached) attribute lookup sees a button type. */
    private function plugin_sees_button(): bool
    {
        $frontend = woo_variation_swatches()->get_frontend();
        return $frontend->is_button_attribute($frontend->get_attribute_taxonomy_by_name('pa_live-size'));
    }

    protected function tearDown(): void
    {
        if ($this->attribute > 0) {
            wc_delete_attribute($this->attribute);
        }
        remove_all_filters('wpmcp_enable_variation_swatches_write');
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args = []): array
    {
        $integration = new Plugin_Data_Integration();
        return 'read' === $half
            ? $integration->handle_read([ 'operation' => $op, 'args' => $args ])
            : $integration->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    public function test_the_real_plugin_is_detected_and_offers_its_types(): void
    {
        $this->assertTrue(Variation_Swatches_Pack::active());
        $out = $this->call('read', 'variationswatches-list-attributes');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $types = array_column($out['result']['types'], 'type');
        foreach ([ 'select', 'color', 'image', 'button', 'radio' ] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertContains($this->attribute, array_column($out['result']['attributes'], 'id'));
    }

    public function test_a_type_change_reaches_the_plugin_and_rolls_back(): void
    {
        $this->assertFalse($this->plugin_sees_button(), 'the plugin caches the select type first');
        $out = $this->call('write', 'variationswatches-set-attribute-type', [ 'attribute_id' => $this->attribute, 'type' => 'button' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $attribute = wc_get_attribute($this->attribute);
        $this->assertSame('button', $attribute->type);
        $this->assertSame('name', $attribute->order_by, 'the other columns stay');
        $this->assertTrue($this->plugin_sees_button(), 'the plugin reads the new type');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('select', wc_get_attribute($this->attribute)->type);
        $this->assertFalse($this->plugin_sees_button(), 'the undo drops the plugin\'s cached copy too');
    }
}
