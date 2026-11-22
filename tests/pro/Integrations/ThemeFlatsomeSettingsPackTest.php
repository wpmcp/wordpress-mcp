<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Flatsome theme options pack: get/set-flatsome-settings on the theme
 * pair while Flatsome (template slug "flatsome", also the template of its
 * child themes) is the active theme, against the theme_mods row of the
 * active stylesheet, which is where Flatsome's Customizer options live.
 */
class ThemeFlatsomeSettingsPackTest extends \WP_UnitTestCase
{
    private \Closure $template;

    private \Closure $stylesheet;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->template   = static fn (): string => 'flatsome';
        $this->stylesheet = static fn (): string => 'flatsome-child';
        add_filter('template', $this->template);
        add_filter('pre_option_template', $this->template);
        add_filter('pre_option_stylesheet', $this->stylesheet);
        add_filter('stylesheet', $this->stylesheet);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('pre_option_template', $this->template);
        remove_filter('pre_option_stylesheet', $this->stylesheet);
        remove_filter('stylesheet', $this->stylesheet);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('theme_mods_flatsome-child');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function write(array $settings): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-flatsome-settings', 'args' => [ 'settings' => $settings ] ]);
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

    public function test_the_pack_is_offered_only_while_flatsome_is_the_active_theme(): void
    {
        $this->assertContains('get-flatsome-settings', $this->names());
        $this->assertContains('set-flatsome-settings', $this->names());

        remove_filter('template', $this->template);
        $other = static fn (): string => 'twentytwentyfour';
        add_filter('template', $other);
        $this->assertNotContains('get-flatsome-settings', $this->names());
        remove_filter('template', $other);
        add_filter('template', $this->template);
    }

    public function test_read_write_and_exact_rollback_of_the_theme_mods_row(): void
    {
        $before = [
            'color_primary'  => '#446084',
            'type_headings'  => [ 'font-family' => 'Lato', 'variant' => '700' ],
            'site_logo'      => 'https://example.test/logo.png',
            'nav_menu_locations' => [ 'primary' => 3 ],
        ];
        update_option('theme_mods_flatsome-child', $before);

        $read = (new Theme_Integration())->handle_read([ 'operation' => 'get-flatsome-settings' ]);
        $this->assertSame('flatsome', $read['result']['framework']);
        $this->assertSame('#446084', $read['result']['settings']['color_primary']);
        $this->assertSame('Lato', $read['result']['settings']['headings_font_family']);
        $this->assertNull($read['result']['settings']['site_width']);
        $this->assertNotContains('site_logo', $read['result']['allowlist']);

        $out = $this->write([
            'color_primary'        => '#ff5500',
            'color_secondary'      => '#d26e4b',
            'color_success'        => '#7a9c59',
            'color_alert'          => '#b20000',
            'color_texts'          => '#777777',
            'color_links'          => '#334862',
            'headings_font_family' => 'Open Sans',
            'texts_font_family'    => 'Roboto',
            'site_width'           => '1200px',
            'header_height'        => 100,
            'header_bg'            => 'rgba(255,255,255,0.9)',
            'header_width'         => 'full-width',
            'footer_left_text'     => 'Copyright [ux_current_year] <strong>Acme</strong><script>x()</script>',
            'footer_right_text'    => '',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $stored = get_option('theme_mods_flatsome-child');
        $this->assertSame('#ff5500', $stored['color_primary']);
        $this->assertSame('#d26e4b', $stored['color_secondary']);
        $this->assertSame([ 'font-family' => 'Open Sans', 'variant' => '700' ], $stored['type_headings']);
        $this->assertSame([ 'font-family' => 'Roboto', 'variant' => '400' ], $stored['type_texts'], 'An unset typography array is seeded with the theme default first');
        $this->assertSame(1200, $stored['site_width']);
        $this->assertSame(100, $stored['header_height']);
        $this->assertSame('rgba(255,255,255,0.9)', $stored['header_bg']);
        $this->assertSame('full-width', $stored['header_width']);
        $this->assertSame('Copyright [ux_current_year] <strong>Acme</strong>x()', $stored['footer_left_text']);
        $this->assertSame('https://example.test/logo.png', $stored['site_logo'], 'Keys off the allowlist are kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('theme_mods_flatsome-child'));
    }

    public function test_invalid_values_and_unknown_keys_are_refused_before_any_snapshot(): void
    {
        update_option('theme_mods_flatsome-child', [ 'color_primary' => '#446084' ]);
        $count = $this->snapshot_count();
        foreach ([ [ 'site_width' => 300 ], [ 'site_width' => 5000 ], [ 'header_height' => 10 ], [ 'color_primary' => 'red;}' ], [ 'header_width' => 'huge' ], [ 'headings_font_family' => 'x;{}' ] ] as $bad) {
            $this->assertSame('invalid_setting_value', $this->write($bad)['error']['code'], (string) wp_json_encode($bad));
        }
        $this->assertSame('key_not_allowlisted', $this->write([ 'site_logo' => 'x' ])['error']['code']);
        $this->assertSame('key_not_allowlisted', $this->write([ 'color_primary' => '#000000', 'custom_css' => 'body{}' ])['error']['code']);
        $this->assertSame($count, $this->snapshot_count());
        $this->assertSame([ 'color_primary' => '#446084' ], get_option('theme_mods_flatsome-child'));
    }

    public function test_writes_stay_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('opt_in_required', $this->write([ 'color_primary' => '#000000' ])['error']['code']);
        $this->assertFalse(get_option('theme_mods_flatsome-child'));
    }

    public function test_the_pack_is_paid_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $this->assertNotContains('get-flatsome-settings', $this->names(), 'Without a licence the paid pack is not offered');
        $this->assertNotContains('set-flatsome-settings', $this->names());
    }
}
