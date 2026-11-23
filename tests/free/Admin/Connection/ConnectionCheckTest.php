<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Connection_Check;
use WPMCP\Admin\Connection\Connection_Evidence;
use WPMCP\Admin\Connection\Oauth_Grant;
use WPMCP\Admin\Connection\Session_Evidence;
use WPMCP\Admin\Connection\Tool_Call;
use WPMCP\Admin\Connection\Verdict;

/**
 * "Check connection" decides from what WordPress recorded, and claims no
 * more: the password's first use (core), the MCP session the app opened (the
 * MCP adapter, with the client's self-reported name) and the first tool call
 * (the request log). Pure: no WordPress, so it runs in the fast tier.
 */
class ConnectionCheckTest extends \WP_UnitTestCase
{
    private const CREATED = 1_000_000;

    /**
     * @param Session_Evidence[] $sessions
     * @param Oauth_Grant[]      $grants
     */
    private function evidence(?int $last_used = null, array $sessions = [], ?Tool_Call $call = null, array $grants = []): Connection_Evidence
    {
        return new Connection_Evidence(
            password_last_used: $last_used,
            password_last_ip: null === $last_used ? null : '203.0.113.24',
            sessions: $sessions,
            tool_call: $call,
            grants: $grants,
        );
    }

    public function test_connected_needs_the_password_used_and_a_session_opened_after_it_was_made(): void
    {
        $older  = new Session_Evidence('cursor', '1.0', self::CREATED - 50, self::CREATED - 10);
        $first  = new Session_Evidence('claude-code', '2.1.4', self::CREATED + 5, self::CREATED + 70);
        $newest = new Session_Evidence('claude-code', '2.1.4', self::CREATED + 9, self::CREATED + 9);
        $call   = new Tool_Call('wpmcp/get-connection-info', self::CREATED + 12, true);

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [$older, $first, $newest], $call), self::CREATED, true);

        $this->assertSame(Verdict::Connected, $result->verdict);
        $this->assertSame($newest, $result->session, 'the newest session after creation is shown');
        $this->assertSame(2, $result->session_count, 'sessions from before the password are not counted');
        $this->assertSame(self::CREATED + 4, $result->password_last_used);
        $this->assertSame('203.0.113.24', $result->password_last_ip);
        $this->assertSame($call, $result->tool_call);
        $this->assertTrue($result->mcp_on);
        $this->assertTrue($result->is_done());
    }

    public function test_a_used_password_without_an_mcp_session_is_only_password_accepted(): void
    {
        $before = new Session_Evidence('claude-code', '2.1.4', self::CREATED - 1, self::CREATED - 1);

        $result = (new Connection_Check())->for_password($this->evidence(self::CREATED + 4, [$before]), self::CREATED, true);

        $this->assertSame(Verdict::PasswordOnly, $result->verdict);
        $this->assertNull($result->session);
        $this->assertSame(0, $result->session_count);
        $this->assertFalse($result->is_done());
    }

    public function test_an_unused_password_is_not_seen_even_when_the_user_has_sessions(): void
    {
        // A session of the same user can come from another password: the
        // check never credits it to this one.
        $other = new Session_Evidence('cursor', '1.0', self::CREATED + 30, self::CREATED + 30);

        $result = (new Connection_Check())->for_password($this->evidence(null, [$other]), self::CREATED, true);

        $this->assertSame(Verdict::NotSeen, $result->verdict);
        $this->assertNull($result->session);
        $this->assertNull($result->password_last_used);
        $this->assertFalse($result->is_done());
    }

    public function test_mcp_switched_off_is_carried_with_any_verdict(): void
    {
        $result = (new Connection_Check())->for_password($this->evidence(), self::CREATED, false);

        $this->assertSame(Verdict::NotSeen, $result->verdict);
        $this->assertFalse($result->mcp_on);
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
