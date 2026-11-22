<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Tracking_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1094: PixelYourSite writes on the plugin-data pair, next to the
 * reads of #899. PixelYourSite 11 keeps each module's settings as one
 * serialized row of {prefix}pys_options (pys_facebook, pys_ga, pys_gtm),
 * the IDs as lists (pixel_id, tracking_id, gtm_id) and each event as a
 * <name>_enabled switch; older versions kept the same arrays as options.
 */
class PixelYourSiteWritesTest extends \WP_UnitTestCase
{
    private const CAPI_TOKEN = 'EAAG-secret-capi-token';

    /** DDL outside any test transaction, so the table is a real one SHOW TABLES lists. */
    public static function wpSetUpBeforeClass(): void
    {
        self::create_table();
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pys_options"); // phpcs:ignore WordPress.DB
    }

    private static function create_table(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pys_options (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, migrated TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY option_name (option_name))");
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_pixelyoursite_active', '__return_true');
        add_filter(Tracking_Pack::ENABLE_FILTER, '__return_true');
        $this->row('pys_facebook', [ 'enabled' => true, 'pixel_id' => [ '111222333444555' ], 'server_access_api_token' => self::CAPI_TOKEN, 'woo_purchase_enabled' => true, 'woo_add_to_cart_enabled' => false ]);
        $this->row('pys_gtm', [ 'enabled' => true, 'gtm_id' => [ 'GTM-ABC123' ] ]);
        $this->row('pys_ga', [ 'enabled' => false, 'tracking_id' => [ 'G-ZZZ999XYZ' ] ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_pixelyoursite_active');
        remove_all_filters(Tracking_Pack::ENABLE_FILTER);
        delete_option('pys_ga');
        parent::tearDown();
    }

    private function row(string $name, array $value): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'pys_options', [ 'option_name' => $name, 'option_value' => maybe_serialize($value) ]);
    }

    private function stored(string $name): array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->prefix . 'pys_options', $name));
        return null === $raw ? [] : (array) maybe_unserialize($raw);
    }

    private function write(array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => 'pixelyoursite-set-settings', 'args' => $args ]);
    }

    public function test_the_write_is_on_the_catalog_and_off_until_the_site_opts_in(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertSame('write', $ops['pixelyoursite-set-settings']['mode'] ?? null);

        remove_all_filters(Tracking_Pack::ENABLE_FILTER);
        $out = $this->write([ 'network' => 'facebook', 'ids' => [ '999888777666555' ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
    }

    public function test_setting_the_meta_pixel_and_toggling_events_rolls_back_and_keeps_the_token(): void
    {
        $out = $this->write([ 'network' => 'facebook', 'ids' => [ '999888777666555' ], 'events' => [ 'woo_add_to_cart_enabled' => true, 'woo_purchase_enabled' => false ] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable'], (string) wp_json_encode($out));
        $this->assertStringNotContainsString(self::CAPI_TOKEN, (string) wp_json_encode($out));
        $row = $this->stored('pys_facebook');
        $this->assertSame([ '999888777666555' ], $row['pixel_id']);
        $this->assertTrue($row['woo_add_to_cart_enabled']);
        $this->assertFalse($row['woo_purchase_enabled']);
        $this->assertSame(self::CAPI_TOKEN, $row['server_access_api_token'], 'the conversion API token stays');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->stored('pys_facebook');
        $this->assertSame([ '111222333444555' ], $row['pixel_id']);
        $this->assertTrue($row['woo_purchase_enabled']);
        $this->assertFalse($row['woo_add_to_cart_enabled']);
    }

    public function test_ga_and_gtm_ids_and_the_module_switch_are_validated_then_written(): void
    {
        $out = $this->write([ 'network' => 'ga', 'ids' => [ 'G-NEW1234', 'AW-123456789' ], 'enabled' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'G-NEW1234', 'AW-123456789' ], $this->stored('pys_ga')['tracking_id']);
        $this->assertTrue($this->stored('pys_ga')['enabled']);

        foreach ([
            [ 'network' => 'facebook', 'ids' => [ 'abc' ] ],
            [ 'network' => 'ga', 'ids' => [ 'GTM-ABC' ] ],
            [ 'network' => 'gtm', 'ids' => [ 'G-123' ] ],
            [ 'network' => 'facebook', 'events' => [ 'not_an_event_enabled' => true ] ],
            [ 'network' => 'facebook', 'events' => [ 'server_access_api_token' => true ] ],
        ] as $bad) {
            $this->assertSame('invalid_setting', $this->write($bad)['error']['code'] ?? null, (string) wp_json_encode($bad));
        }
        $this->assertSame([ 'GTM-ABC123' ], $this->stored('pys_gtm')['gtm_id']);
    }

    public function test_a_site_without_the_table_writes_the_legacy_option_and_rolls_back(): void
    {
        add_filter('query', [ $this, 'hide_table' ]);
        update_option('pys_ga', [ 'enabled' => false, 'tracking_id' => [ 'G-OLD1' ] ]);

        $out = $this->write([ 'network' => 'ga', 'ids' => [ 'G-NEW2' ] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'G-NEW2' ], get_option('pys_ga')['tracking_id']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'G-OLD1' ], get_option('pys_ga')['tracking_id']);
        remove_filter('query', [ $this, 'hide_table' ]);
    }

    /** A site that never migrated to pys_options: the table is not there. */
    public function hide_table(string $query): string
    {
        global $wpdb;
        return str_replace($wpdb->prefix . 'pys_options', $wpdb->prefix . 'pys_options_absent', $query);
    }
}
