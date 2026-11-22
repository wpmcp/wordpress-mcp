<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1011: the Divi theme options pack against the real Divi theme.
 *
 * Runs only in the live Divi leg (bin/test-local.sh --live-divi,
 * WPMCP_DIVI_SRC), where tests/bootstrap.php makes Divi the active theme.
 * A value written through set-divi-settings must be the one Divi's own
 * et_get_option() returns on the same request, must pass Divi's own
 * Customizer sanitizers unchanged, and rollback must put back exactly what
 * Divi read before.
 *
 * @group divi-live
 */
class ThemeDiviSettingsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('et_get_option') || ! function_exists('et_sanitize_header_style') || 'Divi' !== get_template()) {
            $this->markTestSkipped('Needs the real Divi theme (bin/test-local.sh --live-divi, WPMCP_DIVI_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_divi_reads_the_written_options_and_rollback_restores_them(): void
    {
        update_option('et_divi', [ 'accent_color' => '#2ea3f2', 'header_style' => 'left' ]);
        $GLOBALS['et_theme_options'] = null;
        $this->assertSame('#2ea3f2', et_get_option('accent_color'));

        $names = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        $this->assertContains('set-divi-settings', $names);

        $out = (new Theme_Integration())->handle_write([ 'operation' => 'set-divi-settings', 'args' => [ 'settings' => [
            'accent_color'   => '#ff5500',
            'header_style'   => 'centered',
            'content_width'  => 1280,
            'footer_columns' => '3',
            'boxed_layout'   => true,
        ] ] ]);
        $this->assertArrayNotHasKey('error', $out);

        $this->assertSame('#ff5500', et_get_option('accent_color'), 'Divi reads the new value on the same request');
        $this->assertSame('centered', et_get_option('header_style'));
        $this->assertSame(1280, (int) et_get_option('content_width'));
        $this->assertTrue(et_get_option('boxed_layout', false));

        $this->assertSame('#ff5500', et_sanitize_alpha_color('#ff5500'));
        $this->assertSame('centered', et_sanitize_header_style('centered'));
        $this->assertSame('3', et_sanitize_footer_column('3'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'accent_color' => '#2ea3f2', 'header_style' => 'left' ], get_option('et_divi'));
        $this->assertSame('#2ea3f2', et_get_option('accent_color'), 'Divi reads the restored value after the undo');
        $this->assertSame('left', et_get_option('header_style'));
    }
}
