<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Mcp_Client;

/**
 * How an MCP client names itself, read in one place for every revision
 * (issue #1258): clientInfo in the params of an initialize (2025-11-25 and
 * earlier), or in params._meta on every request of 2026-07-28, which has no
 * initialize. The name is untrusted, so it is kept as short plain text.
 * Pure: no WordPress, so it runs in the fast tier.
 */
class McpClientTest extends \WP_UnitTestCase
{
    public function test_a_2025_initialize_names_its_client_in_its_params(): void
    {
        $client = Mcp_Client::from_message([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => [ 'name' => 'cursor', 'version' => '1.7.2' ] ],
        ]);

        $this->assertSame('cursor', $client->name);
        $this->assertSame('1.7.2', $client->version);
        $this->assertTrue($client->is_named());
        $this->assertSame('cursor 1.7.2', $client->label());
    }

    /**
     * What Claude Code 2.1.330 sends on every request (captured on a demo
     * site, 24 Nov 2026): no initialize, the client in _meta.
     */
    public function test_a_2026_request_names_its_client_in_meta_whatever_the_method(): void
    {
        foreach ([ 'server/discover', 'tools/list', 'tools/call' ] as $method) {
            $client = Mcp_Client::from_message([
                'jsonrpc' => '2.0',
                'id'      => 3,
                'method'  => $method,
                'params'  => [
                    'name'  => 'wpmcp-get-connection-info',
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
                        'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'claude-code', 'title' => 'Claude Code', 'version' => '2.1.330' ],
                        'io.modelcontextprotocol/clientCapabilities' => [],
                    ],
                ],
            ]);

            $this->assertSame('claude-code 2.1.330', $client->label(), $method);
        }
    }

    public function test_a_2025_request_after_initialize_names_no_client(): void
    {
        $client = Mcp_Client::from_message([ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [] ]);

        $this->assertFalse($client->is_named());
        $this->assertSame('', $client->label());
        $this->assertTrue($client->equals(Mcp_Client::unnamed()));
    }

    public function test_requests_that_name_nothing_usable_are_unnamed(): void
    {
        $messages = [
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize' ],
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => 'not an object' ],
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [ 'clientInfo' => 'claude-code' ] ],
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [ '_meta' => 'x' ] ],
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [ '_meta' => [ 'io.modelcontextprotocol/clientInfo' => [ 'version' => '1.0' ] ] ] ],
        ];

        foreach ($messages as $i => $message) {
            $this->assertFalse(Mcp_Client::from_message($message)->is_named(), (string) $i);
        }
    }

    public function test_the_params_the_mcp_adapter_stored_with_a_session_read_the_same_way(): void
    {
        $this->assertSame('openai-mcp 1.0.0', Mcp_Client::from_initialize_params([ 'clientInfo' => [ 'name' => 'openai-mcp', 'version' => '1.0.0' ] ])->label());
        $this->assertFalse(Mcp_Client::from_initialize_params([])->is_named());
        $this->assertFalse(Mcp_Client::from_initialize_params(null)->is_named());
    }

    public function test_the_text_a_client_sends_is_plain_capped_and_scalar_only(): void
    {
        $client = Mcp_Client::of('<b>claude</b>' . "\u{202E}\t\n" . str_repeat('x', 300), [ 'nested' ]);

        $this->assertStringNotContainsString('<', $client->name);
        $this->assertStringNotContainsString("\u{202E}", $client->name, 'no bidi override reaches the screen');
        $this->assertStringNotContainsString("\n", $client->name);
        $this->assertStringStartsWith('claude x', $client->name);
        $this->assertLessThanOrEqual(Mcp_Client::TEXT_MAX, mb_strlen($client->name));
        $this->assertSame('', $client->version, 'only a string or a number becomes text');
        $this->assertSame('2', Mcp_Client::of('tool', 2)->version);
    }

    public function test_text_that_is_not_utf8_reads_as_empty(): void
    {
        $this->assertSame('', Mcp_Client::of("\xC3\x28", '1')->name);
    }

    public function test_two_clients_are_equal_by_name_and_version(): void
    {
        $this->assertTrue(Mcp_Client::of('claude-code', '2.1.330')->equals(Mcp_Client::of('claude-code', '2.1.330')));
        $this->assertFalse(Mcp_Client::of('claude-code', '2.1.330')->equals(Mcp_Client::of('claude-code', '2.1.331')));
    }
}
