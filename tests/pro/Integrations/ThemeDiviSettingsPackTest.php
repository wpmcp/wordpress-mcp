<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Divi theme options pack (issue #1011): get/set-divi-settings on the
 * theme pair while Divi (template slug "Divi") is the active theme, against
 * Divi's own one-row et_divi option.
 */
class ThemeDiviSettingsPackTest extends \WP_UnitTestCase
{
    private \Closure $template;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->template = static fn (): string => 'Divi';
        add_filter('template', $this->template);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('et_divi');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function write(array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-divi-settings', 'args' => [ 'settings' => $settings ] ]);
    }

    /** @return string[] */
    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    public function test_the_pack_is_offered_only_while_divi_is_the_active_theme(): void
    {
        $this->assertContains('get-divi-settings', $this->names());
        $this->assertContains('set-divi-settings', $this->names());

        remove_filter('template', $this->template);
        $this->template = static fn (): string => 'twentytwentyfour';
        add_filter('template', $this->template);
        $this->assertNotContains('get-divi-settings', $this->names());
    }

    public function test_read_write_and_exact_rollback_of_the_et_divi_row(): void
    {
        $before = [ 'accent_color' => '#2ea3f2', 'header_style' => 'left', 'divi_logo' => 'https://example.test/logo.png', 'et_pb_post_type_integration' => [ 'post' => 'on' ] ];
        update_option('et_divi', $before);

        $read = (new Theme_Integration())->handle_read([ 'operation' => 'get-divi-settings' ]);
        $this->assertSame('divi', $read['result']['framework']);
        $this->assertSame('#2ea3f2', $read['result']['settings']['accent_color']);
        $this->assertNull($read['result']['settings']['content_width']);
        $this->assertNotContains('divi_logo', $read['result']['allowlist']);

        $out = $this->write([
            'accent_color'                  => '#ff5500',
            'body_font'                     => 'Open Sans',
            'heading_font'                  => 'Lato',
            'body_font_size'                => 16,
            'content_width'                 => '1280px',
            'gutter_width'                  => 2,
            'header_style'                  => 'centered',
            'boxed_layout'                  => true,
            'footer_columns'                => '_1_4__3_4',
            'custom_footer_credits'         => '<strong>Acme</strong><script>x()</script>',
            'disable_custom_footer_credits' => false,
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $stored = get_option('et_divi');
        $this->assertSame('#ff5500', $stored['accent_color']);
        $this->assertSame('Open Sans', $stored['body_font']);
        $this->assertSame(16, $stored['body_font_size']);
        $this->assertSame(1280, $stored['content_width']);
        $this->assertSame(2, $stored['gutter_width']);
        $this->assertSame('centered', $stored['header_style']);
        $this->assertTrue($stored['boxed_layout']);
        $this->assertSame('_1_4__3_4', $stored['footer_columns']);
        $this->assertSame('<strong>Acme</strong>x()', $stored['custom_footer_credits']);
        $this->assertFalse($stored['disable_custom_footer_credits']);
        $this->assertSame('https://example.test/logo.png', $stored['divi_logo'], 'Keys off the allowlist are kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('et_divi'));
    }

    public function test_invalid_values_and_unknown_keys_are_refused_before_any_snapshot(): void
    {
        update_option('et_divi', [ 'accent_color' => '#2ea3f2' ]);
        $count = $this->snapshot_count();
        foreach ([ [ 'header_style' => 'sideways' ], [ 'content_width' => 500 ], [ 'gutter_width' => 5 ], [ 'body_font_size' => 40 ], [ 'accent_color' => 'red;}' ], [ 'boxed_layout' => 'on' ], [ 'footer_columns' => '7' ] ] as $bad) {
            $this->assertSame('invalid_setting_value', $this->write($bad)['error']['code'], (string) wp_json_encode($bad));
        }
        $this->assertSame('key_not_allowlisted', $this->write([ 'divi_logo' => 'x' ])['error']['code']);
        $this->assertSame('key_not_allowlisted', $this->write([ 'accent_color' => '#000000', 'et_pb_product_tour_global' => 'off' ])['error']['code']);
        $this->assertSame($count, $this->snapshot_count());
        $this->assertSame([ 'accent_color' => '#2ea3f2' ], get_option('et_divi'));
    }

    public function test_writes_stay_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('opt_in_required', $this->write([ 'accent_color' => '#000000' ])['error']['code']);
        $this->assertFalse(get_option('et_divi'));
    }

    public function test_the_pack_is_paid_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $this->assertNotContains('get-divi-settings', $this->names(), 'Without a licence the paid pack is not offered');
        $this->assertNotContains('set-divi-settings', $this->names());
    }
}
