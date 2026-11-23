<?php

declare(strict_types=1);

namespace WPMCP\Admin\Connection;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The outcome of "Check connection": the verdict, whether MCP is on, and
 * the evidence the screen shows for it.
 */
final class Check_Result
{
    public function __construct(
        public readonly Verdict $verdict,
        public readonly bool $mcp_on,
        public readonly ?Session_Evidence $session,
        public readonly int $session_count,
        public readonly ?int $password_last_used,
        public readonly ?string $password_last_ip,
        public readonly ?Tool_Call $tool_call,
        public readonly ?Oauth_Grant $grant,
    ) {
    }

    /** Whether the check found what it waits for, so watching can stop. */
    public function is_done(): bool
    {
        return Verdict::Connected === $this->verdict || Verdict::OauthSignedIn === $this->verdict;
    }
}
