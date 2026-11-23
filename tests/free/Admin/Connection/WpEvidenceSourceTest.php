<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Client_App;
use WPMCP\Admin\Connection\Mcp_Activity;
use WPMCP\Admin\Connection\Mcp_Client;
use WPMCP\Admin\Connection\Wp_Activity_Store;
use WPMCP\Admin\Connection\Wp_Evidence_Source;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\MCP\Request_Log;

/**
 * The WordPress side of "Check connection": what core, WP MCP's record of
 * the MCP requests answered per password (issue #1258), the MCP adapter, the
 * request log and the OAuth stores recorded, read without changing any of
 * it. On multisite the adapter keys sessions per site, so the reader does
 * too.
 */
class WpEvidenceSourceTest extends \WP_UnitTestCase
{
    private int $user = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'baker' ]);
        add_filter('wp_is_application_passwords_available', '__return_true');
        delete_option(Request_Log::OPTION);
    }

    protected function tearDown(): void
    {
        remove_filter('wp_is_application_passwords_available', '__return_true');
        Request_Log::set_clock_for_tests(null);
        foreach ([ Request_Log::OPTION, Client_Metadata_Document::REGISTRY_OPTION, Refresh_Token_Store::OPTION, Token_Store::OPTION, Client_Access::OPTION, Client_Store::OPTION ] as $option) {
            delete_option($option);
        }
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    private function source(): Wp_Evidence_Source
    {
        return new Wp_Evidence_Source(new Wp_Activity_Store());
    }

    /** A ChatGPT OAuth client with a token issued to the test user, so for_oauth() reads that user's sessions. */
    private function chatgpt_signed_in(int $issued_at = 300): void
    {
        $registered = Client_Store::create([ 'ChatGPT' ], [ 'https://chatgpt.com/connector_platform_oauth_redirect' ]);
        update_option(Token_Store::OPTION, [
            't1' => [ 'client_id' => (string) $registered['client_id'], 'user_id' => $this->user, 'scope' => Client_Access::SCOPE_FULL, 'issued_at' => $issued_at ],
        ]);
    }

    /** The meta key the MCP adapter stores sessions under on this install. */
    private function session_key(?int $blog_id = null): string
    {
        return is_multisite() ? 'mcp_adapter_sessions_' . ($blog_id ?? get_current_blog_id()) : 'mcp_adapter_sessions';
    }

    public function test_it_reads_the_first_use_core_records_for_the_password(): void
    {
        [, $item] = \WP_Application_Passwords::create_new_application_password($this->user, [ 'name' => 'Claude Code' ]);
        $source   = $this->source();

        $before = $source->for_password($this->user, $item['uuid'], (int) $item['created']);
        $this->assertNull($before->password_last_used);
        $this->assertNull($before->password_last_ip);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.24';
        \WP_Application_Passwords::record_application_password_usage($this->user, $item['uuid']);

        $after = $source->for_password($this->user, $item['uuid'], (int) $item['created']);
        $this->assertIsInt($after->password_last_used);
        $this->assertGreaterThanOrEqual((int) $item['created'], $after->password_last_used);
        $this->assertSame('203.0.113.24', $after->password_last_ip);
    }

    public function test_an_unknown_password_has_no_use(): void
    {
        $evidence = $this->source()->for_password($this->user, 'no-such-uuid', 0);

        $this->assertNull($evidence->password_last_used);
        $this->assertNull($evidence->activity);
        $this->assertSame([], $evidence->grants);
    }

    public function test_it_reads_the_mcp_requests_answered_for_that_password_only(): void
    {
        [, $item]  = \WP_Application_Passwords::create_new_application_password($this->user, [ 'name' => 'Claude Code' ]);
        [, $other] = \WP_Application_Passwords::create_new_application_password($this->user, [ 'name' => 'Cursor' ]);
        (new Wp_Activity_Store())->save($this->user, (string) $other['uuid'], new Mcp_Activity(Mcp_Client::of('cursor', '1.7.2'), 100, 160));

        $this->assertNull($this->source()->for_password($this->user, (string) $item['uuid'], 0)->activity, 'another password\'s requests are not this one\'s');

        (new Wp_Activity_Store())->save($this->user, (string) $item['uuid'], new Mcp_Activity(Mcp_Client::of('claude-code', '2.1.330'), 200, 260));
        $activity = $this->source()->for_password($this->user, (string) $item['uuid'], 0)->activity;

        $this->assertNotNull($activity);
        $this->assertSame('claude-code 2.1.330', $activity->client->label());
        $this->assertSame(200, $activity->first_at);
        $this->assertSame(260, $activity->last_at);
    }

    public function test_a_session_of_the_same_user_is_no_evidence_for_a_password(): void
    {
        update_user_meta($this->user, $this->session_key(), [
            'a' => [ 'created_at' => 100, 'last_activity' => 160, 'client_params' => [ 'clientInfo' => [ 'name' => 'claude-code', 'version' => '2.1.4' ] ] ],
        ]);

        $this->assertSame([], $this->source()->for_password($this->user, 'x', 0)->sessions, 'it can come from another password');
    }

    public function test_it_reads_the_adapter_sessions_and_skips_malformed_rows(): void
    {
        update_user_meta($this->user, $this->session_key(), [
            'a' => [ 'created_at' => 100, 'last_activity' => 160, 'client_params' => [ 'clientInfo' => [ 'name' => 'claude-code', 'version' => '2.1.4' ] ] ],
            'b' => [ 'created_at' => 200, 'last_activity' => 200, 'client_params' => [] ],
            'c' => 'not a session',
            'd' => [ 'created_at' => 'yesterday' ],
        ]);

        $this->chatgpt_signed_in();
        $sessions = $this->source()->for_oauth(Client_App::Chatgpt)->sessions;

        $this->assertCount(2, $sessions);
        $by_time = [];
        foreach ($sessions as $session) {
            $by_time[ $session->created_at ] = $session;
        }
        $this->assertSame('claude-code', $by_time[100]->client_name);
        $this->assertSame('2.1.4', $by_time[100]->client_version);
        $this->assertSame(160, $by_time[100]->last_activity);
        $this->assertSame('', $by_time[200]->client_name, 'a client that reported no name stays unnamed');
    }

    public function test_the_client_reported_name_is_plain_text_and_capped(): void
    {
        update_user_meta($this->user, $this->session_key(), [
            'a' => [ 'created_at' => 100, 'last_activity' => 100, 'client_params' => [ 'clientInfo' => [ 'name' => '<b>' . str_repeat('x', 300), 'version' => [ 'nested' ] ] ] ],
        ]);

        $this->chatgpt_signed_in();
        $session = $this->source()->for_oauth(Client_App::Chatgpt)->sessions[0];

        $this->assertStringNotContainsString('<', $session->client_name);
        $this->assertLessThanOrEqual(64, strlen($session->client_name));
        $this->assertSame('', $session->client_version);
    }

    public function test_sessions_stored_for_another_site_of_a_network_are_not_read(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Per-site session keys exist only on multisite.');
        }

        update_user_meta($this->user, $this->session_key(get_current_blog_id() + 999), [
            'a' => [ 'created_at' => 100, 'last_activity' => 100, 'client_params' => [] ],
        ]);

        $this->chatgpt_signed_in();
        $this->assertSame([], $this->source()->for_oauth(Client_App::Chatgpt)->sessions);
    }

    public function test_it_finds_the_first_tool_call_of_the_user_after_the_password_was_made(): void
    {
        $other = self::factory()->user->create([ 'role' => 'administrator' ]);

        wp_set_current_user($this->user);
        Request_Log::set_clock_for_tests(90);
        Request_Log::record([ 'tool' => 'wpmcp/too-early', 'client' => 'user:' . $this->user, 'ok' => true ]);
        Request_Log::set_clock_for_tests(130);
        Request_Log::record([ 'tool' => 'wpmcp/get-post', 'client' => 'user:' . $this->user, 'ok' => false, 'error_code' => 'not_found' ]);
        Request_Log::set_clock_for_tests(120);
        Request_Log::record([ 'tool' => 'wpmcp/get-connection-info', 'client' => 'user:' . $this->user, 'ok' => true ]);
        wp_set_current_user($other);
        Request_Log::set_clock_for_tests(110);
        Request_Log::record([ 'tool' => 'wpmcp/someone-else', 'client' => 'user:' . $other, 'ok' => true ]);

        $call = $this->source()->for_password($this->user, 'x', 100)->tool_call;

        $this->assertNotNull($call);
        $this->assertSame('wpmcp/get-connection-info', $call->tool);
        $this->assertSame(120, $call->at);
        $this->assertTrue($call->ok);
    }

    public function test_an_oauth_app_reads_its_clients_tokens_and_consent(): void
    {
        $registered = Client_Store::create([ 'ChatGPT' ], [ 'https://chatgpt.com/connector_platform_oauth_redirect' ]);
        $client_id  = (string) $registered['client_id'];
        update_option(Refresh_Token_Store::OPTION, [
            'h1' => [ 'client_id' => $client_id, 'user_id' => $this->user, 'scope' => Client_Access::SCOPE_FULL, 'issued_at' => 5000 ],
            'h2' => [ 'client_id' => $client_id, 'user_id' => $this->user, 'scope' => Client_Access::SCOPE_FULL, 'issued_at' => 7000 ],
            'g1' => [ 'client_id' => $client_id, 'user_id' => $this->user, 'scope' => 'gateway', 'issued_at' => 9000, 'gateway' => true ],
        ]);
        wp_set_current_user($this->user);
        Client_Access::set($client_id, $this->user, Client_Access::READ);
        Client_Metadata_Document::admit([ 'client_id' => 'https://claude.ai/oauth/mcp-client-metadata', 'client_name' => 'Claude', 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ] ]);

        $chatgpt = $this->source()->for_oauth(Client_App::Chatgpt)->grants;

        $this->assertCount(1, $chatgpt, 'one grant per client, the Claude client is not ChatGPT');
        $this->assertSame('ChatGPT', $chatgpt[0]->client_name);
        $this->assertSame(7000, $chatgpt[0]->token_issued_at, 'the newest non-gateway token');
        $this->assertSame('baker', $chatgpt[0]->approved_by);
        $this->assertSame(Client_Access::READ, $chatgpt[0]->access);
        $this->assertGreaterThan(0, $chatgpt[0]->approved_at);
        $this->assertGreaterThan(0, $chatgpt[0]->registered_at);

        $claude = $this->source()->for_oauth(Client_App::ClaudeDesktop)->grants;
        $this->assertCount(1, $claude);
        $this->assertSame('Claude', $claude[0]->client_name);
        $this->assertSame(0, $claude[0]->token_issued_at, 'registered but not signed in yet');
    }

    public function test_the_sessions_of_the_user_who_approved_an_oauth_app_come_with_it(): void
    {
        $registered = Client_Store::create([ 'ChatGPT' ], [ 'https://chatgpt.com/connector_platform_oauth_redirect' ]);
        update_option(Token_Store::OPTION, [
            't1' => [ 'client_id' => (string) $registered['client_id'], 'user_id' => $this->user, 'scope' => Client_Access::SCOPE_FULL, 'issued_at' => 300 ],
        ]);
        update_user_meta($this->user, $this->session_key(), [
            'a' => [ 'created_at' => 310, 'last_activity' => 320, 'client_params' => [ 'clientInfo' => [ 'name' => 'openai-mcp', 'version' => '1.0.0' ] ] ],
        ]);

        $evidence = $this->source()->for_oauth(Client_App::Chatgpt);

        $this->assertSame(300, $evidence->grants[0]->token_issued_at);
        $this->assertCount(1, $evidence->sessions);
        $this->assertSame('openai-mcp', $evidence->sessions[0]->client_name);
    }
}
