<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Connection\Client_App;
use WPMCP\Admin\Connection\Mcp_Activity;
use WPMCP\Admin\Connection\Mcp_Client;
use WPMCP\Admin\Connection\Wp_Activity_Store;
use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Connect\Exposure;

/**
 * The Connection screen in three tabs: "Connect an app" is a four-step flow
 * (pick the app, create the connection, add it to the app, check it) that
 * shows only what the chosen app needs; "Connections" lists what can sign in;
 * "Settings" holds the switches, management access, the uninstall choice and
 * an Advanced disclosure (endpoint, self-test, OAuth discovery, proxies).
 * Every panel is in the page, so the screen works without JavaScript.
 */
class ConnectionLayoutTest extends \WP_UnitTestCase
{
    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'baker' ]);
        wp_set_current_user($this->admin);
        add_filter('wp_is_application_passwords_available', '__return_true');
        delete_option(Connection_Page::SELF_TEST_OPTION);
        delete_option(Connection_Page::OPTION);
        delete_option(Exposure::OPTION);
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        remove_all_filters('wp_is_application_passwords_available');
        delete_option(Connection_Page::SELF_TEST_OPTION);
        delete_option(Connection_Page::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(OAuth_Config::OPTION);
        delete_option(Exposure::OPTION);
        $_POST = [];
        $_GET  = [];
        parent::tearDown();
    }

    /**
     * @param array<string, string> $get
     * @param array<string, mixed>  $post
     */
    private function render(array $get = [], array $post = []): string
    {
        $_GET  = $get;
        $_POST = $post;
        set_current_screen('admin_page_' . Connection_Page::SLUG);
        ob_start();
        (new Connection_Page())->render();
        $html  = (string) ob_get_clean();
        $_GET  = [];
        $_POST = [];

        return $html;
    }

    /** @return array<string, string> */
    private function provision(string $client, array $extra = []): array
    {
        return array_merge([
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'client'                  => $client,
            'user_id'                 => (string) $this->admin,
            'request_id'              => wp_generate_uuid4(),
        ], $extra);
    }

    /** The HTML of one panel. */
    private function panel(string $html, string $tab): string
    {
        $this->assertSame(1, preg_match('#<div class="wpmcp-connection-panel" id="wpmcp-tab-' . $tab . '"[^>]*>(.*?)<!-- /wpmcp-tab-' . $tab . ' -->#s', $html, $m), $tab);

        return $m[1];
    }

    /** The HTML of one step of the Connect flow. */
    private function step(string $html, string $step): string
    {
        $this->assertSame(1, preg_match('#<li class="wpmcp-step[^"]*"[^>]*data-step="' . $step . '"[^>]*>(.*?)<!-- /wpmcp-step-' . $step . ' -->#s', $html, $m), $step);

        return $m[0];
    }

    private function state(string $html, string $step): string
    {
        $this->assertSame(1, preg_match('#data-step="' . $step . '" data-state="([a-z]+)"#', $html, $m), $step);

        return $m[1];
    }

    public function test_the_header_shows_the_mark_the_title_and_the_mcp_state(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<h1>WP MCP: Connection</h1>', $html);
        $this->assertStringContainsString('class="wpmcp-mark"', $html);
        $this->assertStringContainsString('MCP is on', $html);
        $this->assertStringNotContainsString('MCP is off on this site', $html);

        Exposure::set_enabled(false);
        $off = $this->render();
        $this->assertStringContainsString('MCP is off', $off);
        $this->assertStringContainsString('MCP is off on this site', $off, 'a notice on every tab');
        $this->assertMatchesRegularExpression('#name="enabled" value="1"#', $off);
        $this->assertStringContainsString('Turn MCP on', $off);
    }

    public function test_the_screen_has_three_tabs_and_every_panel_is_in_the_page(): void
    {
        $html = $this->render();

        $this->assertSame(3, substr_count($html, 'class="wpmcp-tab"'));
        foreach ([ 'connect' => 'Connect an app', 'connections' => 'Connections', 'settings' => 'Settings' ] as $tab => $label) {
            $this->assertStringContainsString('href="#wpmcp-tab-' . $tab . '"', $html);
            $this->assertStringContainsString($label, $html);
            $this->panel($html, $tab);
        }
        $this->assertStringNotContainsString('Clients and access', $html);
        $this->assertStringNotContainsString('href="#wpmcp-tab-advanced"', $html, 'Advanced lives inside Settings');
    }

    public function test_the_first_visit_shows_the_six_apps_and_locks_the_other_steps(): void
    {
        $html = $this->render();
        $pick = $this->step($html, 'pick');

        $this->assertSame('current', $this->state($html, 'pick'));
        $this->assertSame(6, substr_count($pick, 'class="wpmcp-app"'));
        foreach (Client_App::cases() as $app) {
            $this->assertStringContainsString('client=' . $app->value, $pick);
        }
        foreach ([ 'create', 'add', 'check' ] as $step) {
            $this->assertSame('locked', $this->state($html, $step), $step);
        }
        $this->assertStringNotContainsString('value="provision"', $this->panel($html, 'connect'));
    }

    public function test_picking_an_app_opens_step_two_with_one_create_button(): void
    {
        $html   = $this->render([ 'client' => 'claude-code' ]);
        $create = $this->step($html, 'create');

        $this->assertSame('done', $this->state($html, 'pick'));
        $this->assertStringContainsString('Change app', $this->step($html, 'pick'));
        $this->assertSame('current', $this->state($html, 'create'));
        $this->assertSame(1, substr_count($create, 'value="provision"'));
        $this->assertStringContainsString('name="client" value="claude-code"', $create);
        $this->assertStringContainsString('Create connection', $create);
        $this->assertStringContainsString('least privilege', strtolower($create));
        $this->assertMatchesRegularExpression('#name=["\']user_id["\']#', $create, 'the user picker');
        $this->assertStringContainsString('Claude Code (', $create, 'a sensible default name');
        $this->assertSame('locked', $this->state($html, 'add'));
    }

    public function test_an_unknown_app_value_is_ignored(): void
    {
        $html = $this->render([ 'client' => '<script>alert(1)</script>' ]);

        $this->assertSame('current', $this->state($html, 'pick'));
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_creating_shows_the_password_once_and_only_the_apps_own_steps(): void
    {
        $html   = $this->render([], $this->provision('claude-code'));
        $create = $this->step($html, 'create');
        $add    = $this->step($html, 'add');

        $this->assertSame('done', $this->state($html, 'create'));
        $this->assertSame('current', $this->state($html, 'add'));
        $this->assertSame('open', $this->state($html, 'check'));
        $this->assertStringContainsString('Shown once', $create);
        $this->assertSame(1, preg_match('#<output class="wpmcp-key__value"[^>]*>([^<]+)</output>#', $create, $m));
        $password = str_replace(' ', '', html_entity_decode($m[1]));
        $records  = get_option(Connection_Page::OPTION);
        $this->assertSame('claude-code', $records[0]['client'], 'the ledger remembers the app');
        $item = \WP_Application_Passwords::get_user_application_password($this->admin, $records[0]['uuid']);
        $this->assertTrue(\WP_Application_Passwords::check_password($password, $item['password']), 'the shown password is the one WordPress stored');

        $this->assertStringContainsString('claude mcp add --transport http wpmcp', $add);
        $this->assertStringContainsString(esc_html(home_url('/wp-json/mcp/wpmcp-server')), $add);
        $this->assertStringContainsString(base64_encode('baker:' . $password), $add);
        $this->assertStringContainsString('class="wpmcp-secret"', $add, 'the password is marked so the page can hide it');
        $this->assertStringContainsString('.mcp.json', $add, 'the file route is one of the other ways');
        $this->assertStringNotContainsString('.cursor/mcp.json', $add);
        $this->assertStringNotContainsString('mcp-remote', $add);
        $this->assertStringContainsString('data-uuid="' . esc_attr($records[0]['uuid']) . '"', $this->step($html, 'check'));

        $again = $this->render([ 'client' => 'claude-code' ]);
        $this->assertStringNotContainsString('wpmcp-key__value', $again, 'a plain reload never shows the password again');
        $this->assertStringNotContainsString(base64_encode('baker:' . $password), $again);
    }

    public function test_each_password_app_gets_its_own_instructions(): void
    {
        $expect = [
            'claude-desktop' => [ 'wpmcp_download_bundle', 'Download wpmcp.mcpb' ],
            'cursor'         => [ '.cursor/mcp.json', 'mcpServers' ],
            'antigravity'    => [ 'Copy Config File Path', 'mcp_config.json' ],
            'other'          => [ '.vscode/mcp.json', '&quot;servers&quot;' ],
        ];
        foreach ($expect as $client => $needles) {
            $add = $this->step($this->render([], $this->provision($client)), 'add');
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $add, $client);
            }
        }

        // Both variants are in the page (switching keeps the password); the
        // one the link names is the one that shows.
        $generic = $this->step($this->render([ 'variant' => 'generic' ], $this->provision('other')), 'add');
        $this->assertStringContainsString('Authorization: Basic', $generic);
        $this->assertMatchesRegularExpression('#class="wpmcp-variant" data-variant-panel="generic"#', $generic);
        $this->assertMatchesRegularExpression('#class="wpmcp-variant is-inactive" data-variant-panel="vscode"#', $generic);
    }

    public function test_an_oauth_app_asks_for_oauth_sign_in_then_shows_its_steps(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        delete_option(OAuth_Config::OPTION);
        add_filter('home_url', [ $this, 'public_home' ]);

        $off    = $this->render([ 'client' => 'chatgpt' ]);
        $create = $this->step($off, 'create');
        $this->assertSame('current', $this->state($off, 'create'));
        $this->assertStringContainsString('value="oauth_enabled"', $create);
        $this->assertStringContainsString('Turn on OAuth sign-in', $create);
        $this->assertStringNotContainsString('value="provision"', $create, 'no password for an OAuth app');
        $this->assertSame('locked', $this->state($off, 'add'));

        OAuth_Config::set_enabled(true);
        $on  = $this->render([ 'client' => 'chatgpt' ]);
        $add = $this->step($on, 'add');
        $this->assertSame('done', $this->state($on, 'create'));
        $this->assertStringContainsString('Developer mode', $add);
        $this->assertStringContainsString('https://example.com/wp-json/mcp/wpmcp-server', $add);

        $connector = $this->step($this->render([ 'client' => 'claude-desktop', 'route' => 'connector' ]), 'add');
        $this->assertStringContainsString('Add custom connector', $connector);
        remove_filter('home_url', [ $this, 'public_home' ]);
    }

    public function public_home(string $url): string
    {
        return (string) preg_replace('#^https?://[^/]+#', 'https://example.com', $url);
    }

    public function test_application_passwords_switched_off_block_step_two(): void
    {
        remove_all_filters('wp_is_application_passwords_available');
        add_filter('wp_is_application_passwords_available', '__return_false');
        $message = \WPMCP\Auth\App_Passwords_Status::state(true)['message'] ?? '';

        $html   = $this->render([ 'client' => 'cursor' ]);
        $create = $this->step($html, 'create');

        $this->assertSame('blocked', $this->state($html, 'create'));
        $this->assertStringNotContainsString('value="provision"', $create);
        $this->assertNotSame('', $message);
        $this->assertStringContainsString(esc_html($message), $create, 'the cause and its fix');
    }

    public function test_the_connections_panel_lists_passwords_oauth_clients_and_identities(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $this->issued('Claude Code (today)');
        $client_id = 'https://claude.ai/oauth/mcp-client-metadata';
        Client_Metadata_Document::admit([ 'client_id' => $client_id, 'client_name' => 'Claude', 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ] ]);
        update_option(Refresh_Token_Store::OPTION, [ 'h1' => [ 'client_id' => $client_id, 'user_id' => $this->admin, 'scope' => 'mcp' ] ]);

        $connections = $this->panel($this->render(), 'connections');

        $this->assertStringContainsString('Claude Code (today)', $connections);
        $this->assertStringContainsString('Not used yet', $connections);
        $this->assertStringContainsString('value="revoke"', $connections);
        $this->assertSame(1, preg_match_all('#<tr class="wpmcp-oauth-client"#', $connections));
        $this->assertStringContainsString('name="access"', $connections);
        $this->assertStringContainsString('value="oauth_client_forget"', $connections);
        $this->assertStringContainsString('value="oauth_client_approval"', $connections);
        $this->assertStringNotContainsString('wpmcp_trusted_proxies', $connections, 'proxy internals are under Advanced only');
    }

    /** A ledger record for a real application password of the admin. */
    private function issued(string $name, string $client = 'claude-code'): string
    {
        [ , $item ] = \WP_Application_Passwords::create_new_application_password($this->admin, [ 'name' => $name ]);
        $records    = get_option(Connection_Page::OPTION, []);
        $records[]  = [ 'uuid' => (string) $item['uuid'], 'user_id' => $this->admin, 'name' => $name, 'created' => time(), 'client' => $client ];
        update_option(Connection_Page::OPTION, $records);

        return (string) $item['uuid'];
    }

    public function test_an_entry_whose_password_was_deleted_elsewhere_says_so_and_can_be_removed(): void
    {
        $this->issued('Still here');
        $gone = $this->issued('Deleted on the profile screen', 'cursor');
        \WP_Application_Passwords::delete_application_password($this->admin, $gone);

        $html        = $this->render();
        $connections = $this->panel($html, 'connections');

        $this->assertSame(1, preg_match('#<tr[^>]*data-uuid="' . preg_quote($gone, '#') . '"[^>]*>(.*?)</tr>#s', $connections, $row));
        $this->assertStringContainsString('Removed outside WP MCP', $row[1]);
        $this->assertStringContainsString('value="revoke"', $row[1], 'Remove from list posts the idempotent revoke');
        $this->assertMatchesRegularExpression('#<input type="submit"[^>]*value="Remove from list"#', $row[1]);
        $this->assertStringNotContainsString('value="Revoke"', $row[1]);
        $this->assertStringContainsString('Still here', $connections);
        $this->assertMatchesRegularExpression('#data-tab="connections">Connections <span class="wpmcp-count">1</span>#', $html, 'only connections that can still sign in are counted');

        $after = $this->render([], [ 'wpmcp_connection_action' => 'revoke', '_wpnonce' => wp_create_nonce(Connection_Page::NONCE_ACTION), 'user_id' => (string) $this->admin, 'uuid' => $gone ]);
        $this->assertStringContainsString('Removed from the list', $after);
        $this->assertStringNotContainsString('Deleted on the profile screen', $this->panel($after, 'connections'));
        $this->assertStringContainsString('Still here', $this->panel($after, 'connections'));
    }

    /**
     * Issue #1258: Last used is the newest MCP request the route answered for
     * the password, with the name the client gave, whichever revision it
     * speaks; a client that gave no name gets a neutral label, and a password
     * WordPress accepted without an MCP request says so.
     */
    public function test_the_connections_panel_shows_each_client_and_its_last_activity(): void
    {
        $modern  = $this->issued('Claude Code (today)');
        $legacy  = $this->issued('Cursor (today)', 'cursor');
        $unnamed = $this->issued('Other (today)', 'other');
        $only    = $this->issued('Antigravity (today)', 'antigravity');
        $unused  = $this->issued('Claude Desktop (today)', 'claude-desktop');
        $store   = new Wp_Activity_Store();
        $at      = (int) strtotime('2026-11-24 09:31:00 UTC');
        $store->save($this->admin, $modern, new Mcp_Activity(Mcp_Client::of('claude-code', '2.1.330'), $at - 60, $at));
        $store->save($this->admin, $legacy, new Mcp_Activity(Mcp_Client::from_initialize_params([ 'clientInfo' => [ 'name' => 'cursor', 'version' => '1.7.2' ] ]), $at, $at));
        $store->save($this->admin, $unnamed, new Mcp_Activity(Mcp_Client::unnamed(), $at, $at));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.24';
        \WP_Application_Passwords::record_application_password_usage($this->admin, $only);
        unset($_SERVER['REMOTE_ADDR']);

        $connections = $this->panel($this->render(), 'connections');
        $row         = function (string $uuid) use ($connections): string {
            $this->assertSame(1, preg_match('#<tr[^>]*data-uuid="' . preg_quote($uuid, '#') . '"[^>]*>(.*?)</tr>#s', $connections, $m));
            return $m[1];
        };
        $when = esc_html((string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $at));

        $this->assertStringContainsString($when, $row($modern));
        $this->assertStringContainsString('claude-code 2.1.330', $row($modern));
        $this->assertStringContainsString('cursor 1.7.2', $row($legacy));
        $this->assertStringContainsString('Unnamed MCP client', $row($unnamed));
        $this->assertStringContainsString($when, $row($unnamed));
        $this->assertStringContainsString('Password accepted, no MCP request yet', $row($only));
        $this->assertStringContainsString('Not used yet', $row($unused));
        $this->assertStringNotContainsString('at most once a day', $connections);
    }

    public function test_the_connections_panel_has_an_empty_state(): void
    {
        $connections = $this->panel($this->render(), 'connections');

        $this->assertStringContainsString('No apps connected yet', $connections);
        $this->assertStringContainsString('href="#wpmcp-tab-connect"', $connections);
    }

    public function test_the_settings_panel_holds_the_switches_and_advanced(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $settings = $this->panel($this->render(), 'settings');

        $this->assertStringContainsString('value="toggle"', $settings);
        $this->assertStringContainsString('name="management_mode"', $settings);
        // The switch itself needs a network admin on multisite; the section is there either way.
        $this->assertStringContainsString('Delete all WP MCP data when the plugin is deleted', $settings);
        $this->assertMatchesRegularExpression('#<details class="wpmcp-advanced"#', $settings, 'Advanced starts closed');
        $this->assertStringContainsString('<code id="wpmcp-endpoint">' . esc_html(home_url('/wp-json/mcp/wpmcp-server')) . '</code>', $settings);
        $this->assertStringContainsString('value="self_test"', $settings);
        $this->assertStringContainsString('Not run yet', $settings);
        $this->assertStringContainsString('OAuth discovery', $settings);
        $this->assertStringContainsString('wpmcp_trusted_proxies', $settings);
    }

    public function test_the_last_self_test_result_is_kept(): void
    {
        $reply = static fn () => [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'headers' => [], 'body' => '' ];
        add_filter('pre_http_request', $reply);
        $this->render([], [ 'wpmcp_connection_action' => 'self_test', '_wpnonce' => wp_create_nonce(Connection_Page::NONCE_ACTION) ]);
        remove_filter('pre_http_request', $reply);

        $last = get_option(Connection_Page::SELF_TEST_OPTION);
        $this->assertIsArray($last);
        $this->assertArrayHasKey('ok', $last);
        $this->assertArrayHasKey('at', $last);

        $settings = $this->panel($this->render(), 'settings');
        $this->assertStringNotContainsString('Not run yet', $settings);
        $this->assertStringContainsString(esc_html((string) $last['message']), $settings);
    }

    public function test_destructive_actions_are_styled_and_help_sits_under_controls(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        Client_Metadata_Document::admit([ 'client_id' => 'https://example.com/client.json', 'client_name' => 'Ex', 'redirect_uris' => [ 'https://example.com/cb' ] ]);
        $this->issued('laptop', '');

        $html = $this->render();

        foreach ([ 'Revoke', 'Forget', 'Block' ] as $label) {
            $this->assertMatchesRegularExpression('#<input type="submit"[^>]*class="button delete"[^>]*value="' . $label . '"#', $html, $label);
        }
        $this->assertStringNotContainsString('<span class="description">', $html, 'help text is a block under its control');
    }
}
