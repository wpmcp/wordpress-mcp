<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Variation_Swatches_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Variation Swatches for WooCommerce (issue #1096, wordpress.org slug
 * woo-variation-swatches) on the plugin-data pair, against the real
 * WooCommerce the suite loads. The swatch type is WooCommerce's own
 * attribute_type column of woocommerce_attribute_taxonomies; the plugin adds
 * its types (color, image, button, radio) through
 * product_attributes_type_selector, which this test registers the same way.
 */
class VariationSwatchesPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'variationswatches-list-attributes'  => 'read',
        'variationswatches-get-attribute'    => 'read',
        'variationswatches-set-attribute-type' => 'write',
    ];

    private int $attribute = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('wc_create_attribute')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Snapshot_Store::install();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        // WooCommerce grants this to administrators on install, which the test install skips.
        get_userdata($admin)->add_cap('manage_product_terms');
        wp_set_current_user($admin);
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_variation_swatches_active', '__return_true');
        add_filter('wpmcp_enable_variation_swatches_write', '__return_true');
        add_filter('product_attributes_type_selector', [ $this, 'plugin_types' ]);

        $id = wc_create_attribute([ 'name' => 'Colour', 'slug' => 'vs-colour', 'order_by' => 'name', 'has_archives' => true ]);
        $this->assertIsInt($id);
        $this->attribute = $id;
        register_taxonomy('pa_vs-colour', [ 'product' ]);
        $red = wp_insert_term('Red', 'pa_vs-colour', [ 'slug' => 'red' ]);
        update_term_meta((int) $red['term_id'], 'product_attribute_color', '#ff0000');
        wp_insert_term('Blue', 'pa_vs-colour', [ 'slug' => 'blue' ]);
    }

    protected function tearDown(): void
    {
        if ($this->attribute > 0) {
            wc_delete_attribute($this->attribute);
        }
        remove_all_filters('wpmcp_variation_swatches_active');
        remove_all_filters('wpmcp_enable_variation_swatches_write');
        remove_filter('product_attributes_type_selector', [ $this, 'plugin_types' ]);
        parent::tearDown();
    }

    /** The types the plugin's backend adds (2.6.x). */
    public function plugin_types(array $types): array
    {
        return $types + [ 'color' => 'Color', 'image' => 'Image', 'button' => 'Button', 'radio' => 'Radio' ];
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

    private function stored_type(): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT attribute_type FROM %i WHERE attribute_id = %d', $wpdb->prefix . 'woocommerce_attribute_taxonomies', $this->attribute));
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
        remove_all_filters('wpmcp_variation_swatches_active');
        add_filter('wpmcp_variation_swatches_active', '__return_false');
        $out = $this->read('variationswatches-list-attributes');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Variation Swatches for WooCommerce', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_variation_swatches_active');
        $this->assertSame(defined('WOO_VARIATION_SWATCHES_PLUGIN_VERSION') && function_exists('wc_get_attribute_taxonomies'), Variation_Swatches_Pack::active());
    }

    public function test_list_attributes_reads_the_type_column_and_the_types_on_offer(): void
    {
        $out  = $this->read('variationswatches-list-attributes');
        $rows = array_column($out['attributes'], null, 'id');
        $this->assertArrayHasKey($this->attribute, $rows, (string) wp_json_encode($out));
        $row = $rows[ $this->attribute ];
        $this->assertSame('Colour', $row['name']);
        $this->assertSame('pa_vs-colour', $row['taxonomy']);
        $this->assertSame('select', $row['type']);
        $this->assertFalse($row['swatch']);
        $this->assertSame(2, $row['terms']);
        $this->assertSame('name', $row['order_by']);
        $this->assertTrue($row['has_archives']);
        $this->assertContains('color', array_column($out['types'], 'type'));
        $this->assertTrue($out['writes_enabled']);
    }

    public function test_get_attribute_lists_terms_with_their_swatch_values(): void
    {
        $out   = $this->read('variationswatches-get-attribute', [ 'attribute_id' => $this->attribute ]);
        $terms = array_column($out['attribute']['terms'], null, 'slug');
        $this->assertSame('#ff0000', $terms['red']['color']);
        $this->assertNull($terms['blue']['color']);

        $this->assertSame('attribute_not_found', $this->read('variationswatches-get-attribute', [ 'attribute_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_variation_swatches_write');
        $out = $this->write('variationswatches-set-attribute-type', [ 'attribute_id' => $this->attribute, 'type' => 'color' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_variation_swatches_write', $out['error']['message']);
        $this->assertSame('select', $this->stored_type());
    }

    public function test_an_unknown_type_is_refused_rather_than_saved_as_select(): void
    {
        $out = $this->write('variationswatches-set-attribute-type', [ 'attribute_id' => $this->attribute, 'type' => 'hologram' ]);
        $this->assertSame('unknown_type', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertContains('color', $out['error']['data']['types']);

        $same = $this->write('variationswatches-set-attribute-type', [ 'attribute_id' => $this->attribute, 'type' => 'select' ]);
        $this->assertSame('no_change', $same['error']['code'] ?? null);
    }

    public function test_setting_the_type_keeps_the_other_columns_and_rolls_back(): void
    {
        $out = $this->write('variationswatches-set-attribute-type', [ 'attribute_id' => $this->attribute, 'type' => 'color' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('select', $out['result']['previous_type']);
        $this->assertSame('color', $out['result']['attribute']['type']);
        $this->assertTrue($out['result']['attribute']['swatch']);
        $this->assertSame('color', $this->stored_type());
        $attribute = wc_get_attribute($this->attribute);
        $this->assertSame('color', $attribute->type, 'WooCommerce\'s attribute cache sees the new type');
        $this->assertSame('name', $attribute->order_by);
        $this->assertTrue($attribute->has_archives);
        $this->assertSame('Colour', $attribute->name);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('select', $this->stored_type());
        $this->assertSame('select', wc_get_attribute($this->attribute)->type, 'the undo drops WooCommerce\'s attribute cache too');
        $this->assertSame('select', $this->read('variationswatches-get-attribute', [ 'attribute_id' => $this->attribute ])['attribute']['type']);
    }
}
