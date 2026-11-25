<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Activity_Recorder;
use WPMCP\Admin\Connection\Connection_Check;
use WPMCP\Admin\Connection\Connection_Evidence;
use WPMCP\Admin\Connection\Mcp_Activity;
use WPMCP\Admin\Connection\Mcp_Client;
use WPMCP\Admin\Connection\Oauth_Grant;
use WPMCP\Admin\Connection\Session_Evidence;
use WPMCP\Admin\Connection\Tool_Call;
use WPMCP\Admin\Connection\Verdict;

/**
 * "Check connection" decides from what WordPress recorded, and claims no
 * more: the password's first use (core), the MCP requests the route answered
 * for that very password with the name the client gave (Activity_Recorder,
 * issue #1258) and the first tool call (the request log). An app on MCP
 * 2026-07-28 never sends initialize, so any answered request counts, not an
 * MCP session. Pure: no WordPress, so it runs in the fast tier.
 */
class ConnectionCheckTest extends \WP_UnitTestCase
{
    private const CREATED = 1_000_000;
    private const USER    = 3;
    private const UUID    = '6f1c2a40-1d8e-4c5b-9a7e-0b2d4f6a8c10';

    /**
     * @param Session_Evidence[] $sessions
     * @param Oauth_Grant[]      $grants
     */
    private function evidence(?int $last_used = null, array $sessions = [], ?Tool_Call $call = null, array $grants = [], ?Mcp_Activity $activity = null): Connection_Evidence
    {
        return new Connection_Evidence(
            password_last_used: $last_used,
            password_last_ip: null === $last_used ? null : '203.0.113.24',
            sessions: $sessions,
            tool_call: $call,
            grants: $grants,
            activity: $activity,
        );
    }

    /**
     * The activity the recorder keeps after the route answered these
     * requests for the password, one second apart.
     *
     * @param array<int, array<string, mixed>> $messages decoded JSON-RPC requests
     */
    private function answered(array $messages): ?Mcp_Activity
    {
        $store    = new Fake_Activity_Store();
        $recorder = new Activity_Recorder($store);
        foreach ($messages as $i => $message) {
            $recorder->record(self::USER, self::UUID, Mcp_Client::from_message($message), self::CREATED + 5 + $i);
        }

        return $store->find(self::USER, self::UUID);
    }

    /** @return array<string, mixed> a 2026-07-28 request as Claude Code 2.1.330 sends it */
    private function modern(string $method): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => [ '_meta' => [
                'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
                'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'claude-code', 'version' => '2.1.330' ],
                'io.modelcontextprotocol/clientCapabilities' => [],
            ] ],
        ];
    }

    public function test_a_2026_client_that_never_sends_initialize_is_connected_by_its_requests(): void
    {
        $activity = $this->answered([ $this->modern('server/discover'), $this->modern('tools/list') ]);
        $this->assertNotNull($activity, 'tools/list without initialize is recorded');

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [], null, [], $activity), true);

        $this->assertSame(Verdict::Connected, $result->verdict);
        $this->assertSame($activity, $result->activity);
        $this->assertSame('claude-code 2.1.330', $result->activity->client->label());
        $this->assertSame(self::CREATED + 5, $result->activity->first_at);
        $this->assertSame(self::CREATED + 4, $result->password_last_used);
        $this->assertSame('203.0.113.24', $result->password_last_ip);
        $this->assertTrue($result->is_done());
    }

    public function test_an_initialize_based_client_still_reaches_connected(): void
    {
        $call     = new Tool_Call('wpmcp/get-connection-info', self::CREATED + 12, true);
        $activity = $this->answered([
            [ 'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => [ 'protocolVersion' => '2025-06-18', 'clientInfo' => [ 'name' => 'cursor', 'version' => '1.7.2' ] ] ],
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [] ],
        ]);

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [], $call, [], $activity), true);

        $this->assertSame(Verdict::Connected, $result->verdict);
        $this->assertSame('cursor 1.7.2', $result->activity?->client->label(), 'the session request after initialize keeps the name');
        $this->assertSame($call, $result->tool_call);
        $this->assertTrue($result->is_done());
    }

    public function test_a_client_that_sends_no_name_is_connected_without_one(): void
    {
        $activity = $this->answered([ [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [ '_meta' => [ 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ] ] ] ]);

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [], null, [], $activity), true);

        $this->assertSame(Verdict::Connected, $result->verdict);
        $this->assertFalse($result->activity?->client->is_named());
    }

    public function test_an_answered_request_counts_even_before_core_records_the_use(): void
    {
        $activity = $this->answered([ $this->modern('tools/list') ]);

        $result = (new Connection_Check())->for_password($this->evidence(null, [], null, [], $activity), true);

        $this->assertSame(Verdict::Connected, $result->verdict);
        $this->assertNull($result->password_last_used);
    }

    public function test_a_used_password_with_no_answered_mcp_request_is_only_password_accepted(): void
    {
        // A session of the same user can come from another password: only
        // requests made with this one count.
        $other = new Session_Evidence('claude-code', '2.1.4', self::CREATED + 5, self::CREATED + 9);

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [ $other ]), true);

        $this->assertSame(Verdict::PasswordOnly, $result->verdict);
        $this->assertNull($result->activity);
        $this->assertNull($result->session);
        $this->assertSame(self::CREATED + 4, $result->password_last_used);
        $this->assertFalse($result->is_done());
    }

    public function test_an_unused_password_is_not_seen_even_when_the_user_has_sessions(): void
    {
        $other = new Session_Evidence('cursor', '1.0', self::CREATED + 30, self::CREATED + 30);

        $result = (new Connection_Check())->for_password($this->evidence(null, [ $other ]), true);

        $this->assertSame(Verdict::NotSeen, $result->verdict);
        $this->assertNull($result->session);
        $this->assertNull($result->activity);
        $this->assertNull($result->password_last_used);
        $this->assertFalse($result->is_done());
    }

    public function test_mcp_switched_off_is_carried_with_any_verdict(): void
    {
        $result = (new Connection_Check())->for_password($this->evidence(), false);
        $this->assertSame(Verdict::NotSeen, $result->verdict);
        $this->assertFalse($result->mcp_on);

        $connected = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [], null, [], $this->answered([ $this->modern('tools/list') ])), false);
        $this->assertSame(Verdict::Connected, $connected->verdict);
        $this->assertFalse($connected->mcp_on);
    }

    public function test_an_oauth_app_with_a_token_is_signed_in_and_the_newest_grant_wins(): void
    {
        $old = new Oauth_Grant('ChatGPT', 'approved', self::CREATED - 900, self::CREATED - 800, self::CREATED - 800, 'baker', 'full');
        $new = new Oauth_Grant('ChatGPT', 'approved', self::CREATED, self::CREATED + 60, self::CREATED + 61, 'baker', 'read');
        $session = new Session_Evidence('openai-mcp', '1.0.0', self::CREATED + 62, self::CREATED + 90);

        $result = (new Connection_Check())->for_oauth($this->evidence(null, [$session], null, [$old, $new]), true);

        $this->assertSame(Verdict::OauthSignedIn, $result->verdict);
        $this->assertSame($new, $result->grant);
        $this->assertSame($session, $result->session);
        $this->assertTrue($result->is_done());
    }

    public function test_an_oauth_app_waiting_for_approval_says_so(): void
    {
        $pending = new Oauth_Grant('ChatGPT', 'pending', self::CREATED, 0, 0, '', '');

        $result = (new Connection_Check())->for_oauth($this->evidence(null, [], null, [$pending]), true);

        $this->assertSame(Verdict::OauthWaiting, $result->verdict);
        $this->assertSame($pending, $result->grant);
        $this->assertFalse($result->is_done());
    }

    public function test_an_oauth_app_with_nothing_recorded_is_not_seen(): void
    {
        $result = (new Connection_Check())->for_oauth($this->evidence(), true);

        $this->assertSame(Verdict::NotSeen, $result->verdict);
        $this->assertNull($result->grant);
    }
}
