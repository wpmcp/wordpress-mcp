<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Connection_Assets;

/**
 * The Connection screen's stylesheet and script load on that screen only,
 * including while the WP MCP menu carries the Memory pending badge (which
 * changes the first part of the screen's hook name), and ship inside src/,
 * the directory every release flavor copies.
 */
class ConnectionAssetsTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        wp_dequeue_script(Connection_Assets::HANDLE);
        wp_deregister_script(Connection_Assets::HANDLE);
        wp_dequeue_style(Connection_Assets::HANDLE);
        wp_deregister_style(Connection_Assets::HANDLE);
        parent::tearDown();
    }

    public function test_the_assets_load_on_the_connection_screen_with_or_without_the_badge(): void
    {
        foreach ([ 'wpmcp_page_wpmcp-connection', 'wpmcp-3_page_wpmcp-connection' ] as $hook) {
            (new Connection_Assets())->enqueue($hook);

            $this->assertTrue(wp_script_is(Connection_Assets::HANDLE, 'enqueued'), $hook);
            $this->assertTrue(wp_style_is(Connection_Assets::HANDLE, 'enqueued'), $hook);
            $before = implode("\n", (array) wp_scripts()->get_data(Connection_Assets::HANDLE, 'before'));
            $this->assertStringContainsString('window.wpmcpConnection', $before);
            $this->assertStringContainsString('wpmcp_connection_check', $before);
            $this->assertStringContainsString('admin-ajax.php', $before);

            $this->tearDown_assets();
        }
    }

    public function test_the_assets_stay_off_every_other_screen(): void
    {
        foreach ([ 'index.php', 'toplevel_page_wpmcp', 'wpmcp_page_wpmcp-audit-log', 'settings_page_wpmcp-connection-x' ] as $hook) {
            (new Connection_Assets())->enqueue($hook);

            $this->assertFalse(wp_script_is(Connection_Assets::HANDLE, 'enqueued'), $hook);
            $this->assertFalse(wp_style_is(Connection_Assets::HANDLE, 'enqueued'), $hook);
        }
    }

    /** The hook Connection_Page registers checks the screen before it loads Connection_Assets. */
    public function test_the_page_hook_enqueues_on_its_screen_only(): void
    {
        $page = new \WPMCP\Admin\Connection_Page();

        $page->enqueue_assets('index.php');
        $this->assertFalse(wp_script_is(Connection_Assets::HANDLE, 'enqueued'));

        $page->enqueue_assets('wpmcp-2_page_wpmcp-connection');
        $this->assertTrue(wp_script_is(Connection_Assets::HANDLE, 'enqueued'));
    }

    public function test_the_files_ship_beside_the_class(): void
    {
        $dir = dirname(__DIR__, 4) . '/src/Admin/Connection/assets/';

        $this->assertFileExists($dir . 'connection.css');
        $this->assertFileExists($dir . 'connection.js');

        (new Connection_Assets())->enqueue('wpmcp_page_wpmcp-connection');
        $this->assertStringContainsString('/src/Admin/Connection/assets/connection.js', (string) wp_scripts()->registered[ Connection_Assets::HANDLE ]->src);
        $this->assertStringContainsString('/src/Admin/Connection/assets/connection.css', (string) wp_styles()->registered[ Connection_Assets::HANDLE ]->src);
    }

    private function tearDown_assets(): void
    {
        wp_dequeue_script(Connection_Assets::HANDLE);
        wp_deregister_script(Connection_Assets::HANDLE);
        wp_dequeue_style(Connection_Assets::HANDLE);
        wp_deregister_style(Connection_Assets::HANDLE);
    }
}
