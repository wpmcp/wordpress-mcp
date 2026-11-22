<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Refresh_Token_Store;

/**
 * Issue #1127: the Connection screen opens with a status card (MCP on/off,
 * endpoint with a copy button, last self-test, Run self-test) and groups
 * the rest into Connect, Clients and access, Settings and Advanced panels,
 * with each OAuth client listed once.
 */
class ConnectionLayoutTest extends \WP_UnitTestCase
{
    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        delete_option(Connection_Page::SELF_TEST_OPTION);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        delete_option(Connection_Page::SELF_TEST_OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        $_POST = [];
        parent::tearDown();
    }

    private function render(): string
    {
        set_current_screen('admin_page_' . Connection_Page::SLUG);
        ob_start();
        (new Connection_Page())->render();
        return (string) ob_get_clean();
    }

    /** The HTML of one panel. */
    private function panel(string $html, string $tab): string
    {
        $this->assertSame(1, preg_match('#<div class="wpmcp-connection-panel" id="wpmcp-tab-' . $tab . '"[^>]*>(.*?)<!-- /wpmcp-tab-' . $tab . ' -->#s', $html, $m), $tab);
        return $m[1];
    }

    public function test_status_card_shows_state_endpoint_copy_and_self_test(): void
    {
        $html = $this->render();

        $this->assertSame(1, preg_match('#<div class="card wpmcp-status-card"[^>]*>(.*?)<!-- /wpmcp-status-card -->#s', $html, $m));
        $card = $m[1];
        $this->assertStringContainsString('MCP is on', $card);
        $this->assertStringContainsString('<code id="wpmcp-endpoint">' . esc_html(home_url('/wp-json/mcp/wpmcp-server')) . '</code>', $card);
        $this->assertStringContainsString('data-copy-target="wpmcp-endpoint"', $card);
        $this->assertStringContainsString('value="toggle"', $card);
        $this->assertStringContainsString('value="self_test"', $card);
        $this->assertStringContainsString('Not run yet', $card);
    }

    public function test_the_last_self_test_result_is_kept_on_the_card(): void
    {
        $reply = static fn () => [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'headers' => [], 'body' => '' ];
        add_filter('pre_http_request', $reply);
        $_POST = [ 'wpmcp_connection_action' => 'self_test', '_wpnonce' => wp_create_nonce(Connection_Page::NONCE_ACTION) ];
        $this->render();
        remove_filter('pre_http_request', $reply);
        $_POST = [];

        $last = get_option(Connection_Page::SELF_TEST_OPTION);
        $this->assertIsArray($last);
        $this->assertArrayHasKey('ok', $last);
        $this->assertArrayHasKey('at', $last);

        $html = $this->render();
        $this->assertStringNotContainsString('Not run yet', $html);
        $this->assertStringContainsString(esc_html((string) $last['message']), $html);
    }

    public function test_sections_are_grouped_into_four_panels_with_advanced_collapsed(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $html = $this->render();

        $this->assertStringContainsString('<h1>WP MCP: Connection</h1>', $html);
        $this->assertSame(1, substr_count($html, 'class="nav-tab-wrapper wpmcp-connection-tabs"'));
        foreach ([ 'connect' => 'Connect', 'clients' => 'Clients and access', 'settings' => 'Settings', 'advanced' => 'Advanced' ] as $tab => $label) {
            $this->assertStringContainsString('href="#wpmcp-tab-' . $tab . '"', $html);
            $this->assertStringContainsString('>' . $label . '</a>', $html);
        }

        $connect = $this->panel($html, 'connect');
        $this->assertStringContainsString('value="provision"', $connect);
        $this->assertStringContainsString('Custom connector', $connect);
        $this->assertStringContainsString('ChatGPT', $connect);

        $clients = $this->panel($html, 'clients');
        $this->assertStringContainsString('Issued application passwords', $clients);
        $this->assertStringContainsString('OAuth clients', $clients);
        $this->assertStringContainsString('value="oauth_client_approval"', $clients);

        $settings = $this->panel($html, 'settings');
        $this->assertStringContainsString('value="uninstall_data"', $settings);
        $this->assertStringContainsString('name="management_mode"', $settings);

        $advanced = $this->panel($html, 'advanced');
        $this->assertMatchesRegularExpression('#<details class="wpmcp-advanced">#', $advanced, 'collapsed by default');
        $this->assertStringContainsString('OAuth discovery', $advanced);
        $this->assertStringContainsString('wpmcp_trusted_proxies', $advanced);
        $this->assertStringNotContainsString('wpmcp_trusted_proxies', $clients, 'proxy internals are under Advanced only');
    }

    public function test_each_oauth_client_appears_once_with_access_status_and_actions(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $client_id = 'https://claude.ai/oauth/mcp-client-metadata';
        Client_Metadata_Document::admit([ 'client_id' => $client_id, 'client_name' => 'Claude', 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ] ]);
        update_option(Refresh_Token_Store::OPTION, [ 'h1' => [ 'client_id' => $client_id, 'user_id' => $this->admin, 'scope' => 'mcp' ] ]);

        $clients = $this->panel($this->render(), 'clients');

        $this->assertSame(1, preg_match_all('#<tr class="wpmcp-oauth-client"#', $clients));
        $this->assertSame(1, substr_count($clients, '<code>' . esc_html($client_id) . '</code>'));
        $this->assertStringContainsString('name="access"', $clients);
        $this->assertStringContainsString('value="oauth_client_forget"', $clients);
        $this->assertStringContainsString('value="oauth_client_deny"', $clients);
        $this->assertStringNotContainsString('Connected applications', $clients, 'no second table');
    }

    public function test_destructive_actions_are_styled_and_help_sits_under_controls(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        Client_Metadata_Document::admit([ 'client_id' => 'https://example.com/client.json', 'client_name' => 'Ex', 'redirect_uris' => [ 'https://example.com/cb' ] ]);
        update_option(Connection_Page::OPTION, [ [ 'uuid' => 'u-1', 'user_id' => $this->admin, 'name' => 'laptop', 'created' => time() ] ]);

        $html = $this->render();

        foreach ([ 'Revoke', 'Forget', 'Block' ] as $label) {
            $this->assertMatchesRegularExpression('#<input type="submit"[^>]*class="button delete"[^>]*value="' . $label . '"#', $html, $label);
        }
        $this->assertStringNotContainsString('<span class="description">', $html, 'help text is a block under its control');
    }
}
