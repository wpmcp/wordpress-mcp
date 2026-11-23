<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Mcp_Activity;
use WPMCP\Admin\Connection\Mcp_Client;
use WPMCP\Admin\Connection\Wp_Activity_Store;
use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Connect\Exposure;

/**
 * The Connection screen's two AJAX actions, both behind the management guard
 * and the screen's nonce: wpmcp_connection_create makes the application
 * password (the plaintext is in that one response and nowhere else) and
 * returns the steps drawn with it; wpmcp_connection_check reads what
 * WordPress recorded and never returns a secret.
 *
 * @group ajax
 */
class ConnectionAjaxTest extends \WP_Ajax_UnitTestCase
{
    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'baker' ]);
        wp_set_current_user($this->admin);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('pre_http_request', [ $this, 'stub_http' ], 10, 3);
        delete_option(Connection_Page::OPTION);
        delete_option(Connection_Page::SELF_TEST_OPTION);
    }

    protected function tearDown(): void
    {
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('pre_http_request', [ $this, 'stub_http' ], 10);
        remove_all_filters('wpmcp_oauth_enabled');
        foreach ([ Connection_Page::OPTION, Connection_Page::SELF_TEST_OPTION, Refresh_Token_Store::OPTION, Client_Store::OPTION, Exposure::OPTION ] as $option) {
            delete_option($option);
        }
        delete_user_meta($this->admin, 'mcp_adapter_sessions');
        $_POST = [];
        parent::tearDown();
    }

    /**
     * The admin-ajax cycle fires admin_init, and with it core's update
     * checks; answer those with an empty 200 (see RestoreControllerAjaxTest).
     * The self-test's loopback request to the MCP endpoint gets a 401, an
     * endpoint that is up and wants credentials.
     *
     * @param mixed $preempt
     * @param array<string, mixed> $args
     * @return mixed
     */
    public function stub_http($preempt, $args, string $url)
    {
        if (false !== strpos($url, 'api.wordpress.org')) {
            return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
        }
        if (false !== strpos($url, '/wp-json/mcp/wpmcp-server')) {
            return [ 'headers' => [ 'cache-control' => 'no-store', 'x-accel-buffering' => 'no' ], 'body' => '', 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'cookies' => [], 'filename' => null ];
        }

        return $preempt;
    }

    /**
     * @param array<string, string> $post
     * @return array<string, mixed>
     */
    private function ajax(string $action, array $post): array
    {
        $_POST = $post;
        try {
            $this->_handleAjax($action);
        } catch (\WPAjaxDieContinueException $e) {
            unset($e);
        } catch (\WPAjaxDieStopException $e) {
            unset($e);
        }
        $json                 = json_decode($this->_last_response, true);
        $this->_last_response = '';
        $_POST                = [];

        return is_array($json) ? $json : [];
    }

    private function nonce(): string
    {
        return wp_create_nonce(Connection_Page::NONCE_ACTION);
    }

    /** @return array{0: string, 1: array<string, mixed>} the plaintext and its ledger record */
    private function issue(string $client = 'claude-code'): array
    {
        $made = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => $this->nonce(),
            'client'                  => $client,
            'user_id'                 => (string) $this->admin,
            'request_id'              => wp_generate_uuid4(),
        ]);
        $this->assertIsArray($made);
        $this->assertArrayNotHasKey('error', $made);

        return [ (string) $made['password'], get_option(Connection_Page::OPTION)[0] ];
    }

    public function test_create_makes_one_password_and_returns_the_steps_drawn_with_it(): void
    {
        $out = $this->ajax('wpmcp_connection_create', [ '_wpnonce' => $this->nonce(), 'client' => 'cursor', 'request_id' => 'r-1', 'user_id' => (string) $this->admin ]);

        $this->assertTrue($out['success'] ?? false, wp_json_encode($out));
        $records = get_option(Connection_Page::OPTION);
        $this->assertCount(1, $records);
        $this->assertSame('cursor', $records[0]['client']);
        $this->assertSame($records[0]['uuid'], $out['data']['uuid']);

        $html = (string) $out['data']['html'];
        $this->assertStringContainsString('.cursor/mcp.json', $html);
        $this->assertSame(1, preg_match('#Basic <span class="wpmcp-secret">([A-Za-z0-9+/=]+)</span>#', $html, $m), 'the token is wrapped so the page can hide it');
        [ $login, $password ] = explode(':', (string) base64_decode($m[1]), 2);
        $this->assertSame('baker', $login);
        $item = \WP_Application_Passwords::get_user_application_password($this->admin, $records[0]['uuid']);
        $this->assertTrue(\WP_Application_Passwords::check_password($password, $item['password']));
    }

    public function test_create_refuses_a_bad_nonce_and_makes_nothing(): void
    {
        $out = $this->ajax('wpmcp_connection_create', [ '_wpnonce' => 'bogus', 'client' => 'cursor', 'request_id' => 'r-2', 'user_id' => (string) $this->admin ]);

        $this->assertFalse($out['success'] ?? true);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin));
    }

    public function test_create_refuses_someone_who_cannot_manage_wp_mcp(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->ajax('wpmcp_connection_create', [ '_wpnonce' => $this->nonce(), 'client' => 'cursor', 'request_id' => 'r-3', 'user_id' => (string) $this->admin ]);

        $this->assertFalse($out['success'] ?? true);
        $this->assertSame([], \WP_Application_Passwords::get_user_application_passwords($this->admin));
    }

    public function test_sending_the_same_request_twice_never_makes_a_second_password(): void
    {
        $post = [ '_wpnonce' => $this->nonce(), 'client' => 'cursor', 'request_id' => 'same-request', 'user_id' => (string) $this->admin ];

        $first  = $this->ajax('wpmcp_connection_create', $post);
        $second = $this->ajax('wpmcp_connection_create', $post);

        $this->assertTrue($first['success'] ?? false);
        $this->assertFalse($second['success'] ?? true);
        $this->assertStringContainsString('Already created', (string) ($second['data']['message'] ?? ''));
        $this->assertCount(1, \WP_Application_Passwords::get_user_application_passwords($this->admin));
    }

    public function test_check_goes_from_not_seen_to_connected_and_never_returns_a_secret(): void
    {
        [ $password, $record ] = $this->issue();
        $post                  = [ '_wpnonce' => $this->nonce(), 'client' => 'claude-code', 'uuid' => (string) $record['uuid'] ];

        $before = $this->ajax('wpmcp_connection_check', $post);
        $this->assertTrue($before['success'] ?? false, wp_json_encode($before));
        $this->assertSame('not_seen', $before['data']['verdict']);
        $this->assertFalse($before['data']['done']);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.24';
        \WP_Application_Passwords::record_application_password_usage($this->admin, (string) $record['uuid']);
        unset($_SERVER['REMOTE_ADDR']);
        // A session of the same user may come from another password: not evidence (issue #1258).
        update_user_meta($this->admin, is_multisite() ? 'mcp_adapter_sessions_' . get_current_blog_id() : 'mcp_adapter_sessions', [
            's' => [ 'created_at' => time() + 1, 'last_activity' => time() + 1, 'client_params' => [ 'clientInfo' => [ 'name' => 'cursor', 'version' => '1.0' ] ] ],
        ]);

        $accepted = $this->ajax('wpmcp_connection_check', $post);
        $this->assertSame('password_only', $accepted['data']['verdict']);
        $this->assertFalse($accepted['data']['done']);
        $this->assertStringContainsString('Password accepted, no MCP request yet', (string) $accepted['data']['html']);
        $this->assertStringNotContainsString('MCP session', (string) $accepted['data']['html']);

        // What the route records once it answered a request made with the
        // password, such as a 2026-07-28 tools/list (ConnectionActivityHttpTest).
        (new Wp_Activity_Store())->save($this->admin, (string) $record['uuid'], new Mcp_Activity(Mcp_Client::of('claude-code', '2.1.330'), time(), time()));

        $after = $this->ajax('wpmcp_connection_check', $post);
        $this->assertSame('connected', $after['data']['verdict']);
        $this->assertTrue($after['data']['done']);
        $html = (string) $after['data']['html'];
        $this->assertStringContainsString('claude-code 2.1.330 signed in', $html);
        $this->assertStringContainsString('MCP requests answered', $html);
        $this->assertStringContainsString('203.0.113.24', $html);
        $this->assertStringNotContainsString('cursor', $html, 'the other session is not shown');
        $this->assertStringNotContainsString('MCP session', $html);

        foreach ([ $before, $accepted, $after ] as $out) {
            $text = (string) wp_json_encode($out);
            $this->assertStringNotContainsString($password, $text);
            $this->assertStringNotContainsString(base64_encode('baker:' . $password), $text);
            $this->assertStringNotContainsString('Basic ', $text);
        }
    }

    public function test_a_client_that_sends_no_name_is_connected_under_a_neutral_label(): void
    {
        [ , $record ] = $this->issue();
        (new Wp_Activity_Store())->save($this->admin, (string) $record['uuid'], new Mcp_Activity(Mcp_Client::unnamed(), time(), time()));

        $out = $this->ajax('wpmcp_connection_check', [ '_wpnonce' => $this->nonce(), 'client' => 'claude-code', 'uuid' => (string) $record['uuid'] ]);

        $this->assertSame('connected', $out['data']['verdict']);
        $this->assertStringContainsString('An MCP client signed in', (string) $out['data']['html']);
        $this->assertStringContainsString('By an MCP client that sent no name.', (string) $out['data']['html']);
    }

    public function test_check_only_reads_passwords_wp_mcp_issued(): void
    {
        [ , $item ] = \WP_Application_Passwords::create_new_application_password($this->admin, [ 'name' => 'made elsewhere' ]);

        $out = $this->ajax('wpmcp_connection_check', [ '_wpnonce' => $this->nonce(), 'client' => 'claude-code', 'uuid' => (string) $item['uuid'] ]);

        $this->assertFalse($out['success'] ?? true);
    }

    public function test_check_refuses_a_bad_nonce(): void
    {
        [ , $record ] = $this->issue();

        $out = $this->ajax('wpmcp_connection_check', [ '_wpnonce' => 'bogus', 'client' => 'claude-code', 'uuid' => (string) $record['uuid'] ]);

        $this->assertFalse($out['success'] ?? true);
    }

    public function test_check_for_an_oauth_app_reports_the_sign_in(): void
    {
        $post = [ '_wpnonce' => $this->nonce(), 'client' => 'chatgpt' ];
        $this->assertSame('not_seen', $this->ajax('wpmcp_connection_check', $post)['data']['verdict']);

        $registered = Client_Store::create([ 'ChatGPT' ], [ 'https://chatgpt.com/connector_platform_oauth_redirect' ]);
        update_option(Refresh_Token_Store::OPTION, [ 'h1' => [ 'client_id' => (string) $registered['client_id'], 'user_id' => $this->admin, 'scope' => 'mcp', 'issued_at' => time() ] ]);

        $out = $this->ajax('wpmcp_connection_check', $post);
        $this->assertSame('oauth_signed_in', $out['data']['verdict']);
        $this->assertTrue($out['data']['done']);
        $this->assertStringContainsString('ChatGPT', (string) $out['data']['html']);
    }

    public function test_a_check_that_asks_for_the_self_test_runs_and_keeps_it(): void
    {
        [ , $record ] = $this->issue();

        $out = $this->ajax('wpmcp_connection_check', [ '_wpnonce' => $this->nonce(), 'client' => 'claude-code', 'uuid' => (string) $record['uuid'], 'selftest' => '1' ]);

        $this->assertSame('not_seen', $out['data']['verdict']);
        $this->assertStringContainsString('Server self-test', (string) $out['data']['html']);
        $this->assertIsArray(get_option(Connection_Page::SELF_TEST_OPTION), 'the Settings tab shows it as the last run');
    }

    public function test_a_check_says_when_mcp_is_off(): void
    {
        [ , $record ] = $this->issue();
        Exposure::set_enabled(false);

        $out = $this->ajax('wpmcp_connection_check', [ '_wpnonce' => $this->nonce(), 'client' => 'claude-code', 'uuid' => (string) $record['uuid'] ]);

        $this->assertStringContainsString('MCP is off', (string) $out['data']['html']);
    }
}
