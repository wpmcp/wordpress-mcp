<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Refusal_Result;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\MCP\Unavailable;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Kses\Kses_Markup;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;

/**
 * Issue #628 acceptance, on the wire: one tool per reason code, called over
 * stdio and over HTTP (a 2025-11-25 session and a 2026-07-28 request), comes
 * back as the same isError result carrying the same envelope three ways.
 * Real refusals keep their codes the same way: a rate limit with its
 * retry_after, a capability refusal, a closed opt-in, the kses refusal, a
 * dispatcher pair's error envelope. A tool this site declares but does not
 * register for the connection answers with its refusal; a name nothing
 * declares is still a protocol error.
 */
final class RefusalEnvelopeWireTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const ROUTE = '/mcp/wpmcp-server';

    private ?string $session = null;

    private ?Registrar $original = null;

    /** @var list<string> */
    private array $fixtures = [];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(static fn (): int => 1_790_000_628);
        add_filter('wpmcp_rate_limit', static fn (): int => 100000);
        $this->log_in('administrator');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $name) {
            if (wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }
        if (null !== $this->original) {
            (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        }
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    private function log_in(string $role): void
    {
        $id = self::factory()->user->create([ 'role' => $role ]);
        if (is_multisite() && 'administrator' === $role) {
            grant_super_admin($id);
        }
        wp_set_current_user($id);
        $this->session = null;
    }

    /**
     * Swaps in a fresh Registrar and declares abilities on it in a private
     * wp_abilities_api_init, so the fixtures reach the live registry without
     * touching the shared one. tearDown() puts the real one back.
     *
     * @param callable(Registrar): void $declare
     * @param list<string>              $names   the fixtures that get registered, for tearDown()
     */
    private function declare(callable $declare, array $names): void
    {
        $plugin   = Plugin::instance();
        $property = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->original ??= $property->getValue($plugin);
        $fresh = new Registrar();
        $property->setValue($plugin, $fresh);
        $this->fixtures = array_merge($this->fixtures, $names);

        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($declare, $fresh): void {
            $declare($fresh);
        });
        do_action('wp_abilities_api_init');
    }

    private static function fixture(string $name, callable $handler, string $tier = 'free'): Ability
    {
        return new Ability($name, $tier, 'Issue 628 fixture', [ 'type' => 'object', 'properties' => [] ], $handler, 'read', 'content', 'read');
    }

    private static function fixture_name(string $reason): string
    {
        return 'wpmcp/fixture-628-' . str_replace('_', '-', $reason);
    }

    /** Mounts the HTTP route on a fresh REST server, as the other transport suites do. */
    private function mount(): void
    {
        global $wp_rest_server;

        $adapter    = \WP\MCP\Core\McpAdapter::instance();
        $reflection = new \ReflectionClass($adapter);
        if ($reflection->hasProperty('servers')) {
            $reflection->getProperty('servers')->setValue($adapter, []);
        }
        if ($reflection->hasProperty('initialized')) {
            $reflection->getProperty('initialized')->setValue(null, false);
        }
        add_action('rest_api_init', [ $adapter, 'init' ], 15);

        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
        $this->session = null;
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function post(array $message, array $headers = []): array
    {
        global $wp_rest_server;

        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $request->set_body((string) wp_json_encode($message));

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);

        return [ $response->get_status(), (array) json_decode((string) wp_json_encode($response->get_data()), true), $response->get_headers() ];
    }

    /** The 2025-11-25 session of the current user, opened once. */
    private function session(): string
    {
        if (null === $this->session) {
            [ $status, $body, $headers ] = $this->post([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => [ 'name' => 'contract-628', 'version' => '0' ] ],
            ]);
            $this->assertSame(200, $status, (string) wp_json_encode($body));
            $this->session = (string) ($headers['Mcp-Session-Id'] ?? '');
            $this->assertNotSame('', $this->session);
        }

        return $this->session;
    }

    /** @return array<string, mixed> */
    private static function modern_meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
    }

    /**
     * One tools/call over HTTP, decoded.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function http(string $tool, array $arguments = [], bool $modern = false): array
    {
        $params = [ 'name' => $tool, 'arguments' => $arguments ];
        if ($modern) {
            $params['_meta'] = self::modern_meta();
            $headers         = [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool ];
        } else {
            $headers = [ 'Mcp-Session-Id' => $this->session(), 'MCP-Protocol-Version' => '2025-11-25' ];
        }
        [ $status, $body ] = $this->post([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => $params ], $headers);

        return [ $status, $body ];
    }

    /**
     * One tools/call over stdio, decoded the way the client reads it.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function stdio(string $tool, array $arguments = [], bool $modern = false): array
    {
        $params = [ 'name' => $tool, 'arguments' => $arguments ];
        if ($modern) {
            $params['_meta'] = self::modern_meta();
        }
        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => $params ]);

        return (array) json_decode((string) wp_json_encode($response), true);
    }

    /**
     * The same call on both transports, on one revision: the two results,
     * after asserting they are identical.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the result
     */
    private function on_both(string $tool, array $arguments = [], bool $modern = false): array
    {
        $stdio = $this->stdio($tool, $arguments, $modern);
        [ $status, $http ] = $this->http($tool, $arguments, $modern);

        $this->assertArrayHasKey('result', $stdio, $tool . ' over stdio: ' . wp_json_encode($stdio));
        $this->assertSame(200, $status, $tool . ' over HTTP: ' . wp_json_encode($http));
        $this->assertSame($stdio['result'], $http['result'] ?? null, $tool . ($modern ? ' (2026-07-28)' : ' (2025-11-25)') . ': HTTP and stdio differ');

        return $stdio['result'];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function envelope(array $result): array
    {
        $this->assertTrue($result['isError'] ?? null, (string) wp_json_encode($result));
        $this->assertArrayNotHasKey('structuredContent', $result);
        $envelope = $result['_meta'][ Refusal_Result::META_KEY ] ?? null;
        $this->assertIsArray($envelope, (string) wp_json_encode($result));
        $this->assertSame($envelope, json_decode((string) ($result['content'][1]['text'] ?? ''), true), 'The JSON block is the envelope.');

        return $envelope;
    }

    public function test_every_reason_code_reaches_both_transports_as_one_envelope(): void
    {
        $reasons = array_keys(Unavailable::REASONS);
        $this->declare(static function (Registrar $registrar) use ($reasons): void {
            foreach ($reasons as $reason) {
                $overrides = 'rate_limited' === $reason ? [ 'retry_after' => 30 ] : [];
                $registrar->register(self::fixture(
                    self::fixture_name($reason),
                    static fn (): \WP_Error => ( new Unavailable($reason, sprintf('Stopped by %s.', $reason), 'Do the fix.', $overrides) )->to_wp_error('', [ 'fixture' => 628 ])
                ));
            }
        }, array_map([ self::class, 'fixture_name' ], $reasons));
        $this->mount();

        foreach ($reasons as $reason) {
            $defaults = Unavailable::REASONS[ $reason ];
            $expected = [
                'code'      => $reason,
                'reason'    => $reason,
                'message'   => sprintf('Stopped by %s. Do the fix.', $reason),
                'action'    => [ 'who' => $defaults['who'], 'text' => 'Do the fix.' ],
                'docs_url'  => Unavailable::docs_url($reason),
                'retryable' => $defaults['retryable'],
            ];
            if ('rate_limited' === $reason) {
                $expected['retry_after'] = 30;
            }
            $expected += [ 'hidden' => $defaults['hidden'], 'data' => [ 'fixture' => 628 ] ];

            foreach ([ false, true ] as $modern) {
                $result = $this->on_both(Stdio_Transport::tool_name(self::fixture_name($reason)), [], $modern);
                $this->assertSame($expected, $this->envelope($result), $reason);
                $this->assertSame(
                    sprintf('Unavailable (%s): Stopped by %s. Do the fix. Docs: %s', $reason, $reason, Unavailable::docs_url($reason)),
                    $result['content'][0]['text']
                );
            }
        }
    }

    public function test_a_rate_limited_call_carries_retry_after_in_meta_on_both_transports(): void
    {
        $this->mount();
        remove_all_filters('wpmcp_rate_limit');
        add_filter('wpmcp_rate_limit', static fn (): int => 1);

        // A fresh window per transport, each 12 seconds before its end.
        $throttled = [];
        foreach ([ 'stdio' => 1_790_000_628, 'http' => 1_790_000_688 ] as $transport => $now) {
            Rate_Limiter::set_clock_override(static fn (): int => $now);
            $call = fn (): array => 'stdio' === $transport ? $this->stdio('wpmcp-list-operations') : $this->http('wpmcp-list-operations')[1];

            $first = $call();
            $this->assertFalse($first['result']['isError'] ?? null, (string) wp_json_encode($first));
            $throttled[ $transport ] = $call()['result'] ?? null;
        }

        $this->assertSame($throttled['stdio'], $throttled['http']);
        $envelope = $this->envelope((array) $throttled['stdio']);
        $this->assertSame('wpmcp_rate_limited', $envelope['code']);
        $this->assertSame('rate_limited', $envelope['reason']);
        $this->assertSame('later', $envelope['retryable']);
        $this->assertSame(12, $envelope['retry_after']);
        $this->assertSame([ 'retry_after' => 12, 'remaining' => 0 ], $envelope['data']);
        $this->assertStringStartsWith('Unavailable (rate_limited): Rate limit exceeded for "wpmcp/list-operations". Retry after 12 second(s).', $throttled['stdio']['content'][0]['text']);
    }

    public function test_a_capability_refusal_keeps_its_code_and_reason_on_both_transports(): void
    {
        $this->log_in('subscriber');
        $this->mount();

        $envelope = $this->envelope($this->on_both('wpmcp-get-settings'));

        $this->assertSame('operation_denied', $envelope['code']);
        $this->assertSame('capability_missing', $envelope['reason']);
        $this->assertSame('manage_options', $envelope['data']['capability']);
        $this->assertSame('wpmcp-get-settings', $envelope['data']['tool']);
    }

    public function test_a_closed_opt_in_keeps_its_code_and_names_the_filter(): void
    {
        $this->mount();
        $comment = (int) self::factory()->comment->create([ 'comment_post_ID' => self::factory()->post->create() ]);

        foreach ([ false, true ] as $modern) {
            $result   = $this->on_both('wpmcp-delete-comment', [ 'id' => $comment, 'confirm' => true ], $modern);
            $envelope = $this->envelope($result);

            $this->assertSame('opt_in_required', $envelope['code']);
            $this->assertSame('opt_in_required', $envelope['reason']);
            $this->assertFalse($envelope['hidden']);
            $this->assertSame('wpmcp_enable_delete_comment', $envelope['data']['filter']);
            $this->assertStringContainsString("add_filter('wpmcp_enable_delete_comment', '__return_true');", $envelope['action']['text']);
            $this->assertStringStartsWith('Unavailable (opt_in_required): The delete-comment tool is disabled.', $result['content'][0]['text']);
        }
        $this->assertInstanceOf(\WP_Comment::class, get_comment($comment));
    }

    public function test_the_kses_refusal_code_reaches_the_wire(): void
    {
        $content = "<!-- wp:html -->\n" . Kses_Markup::STORED . "\n<!-- /wp:html -->\n\n<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";
        $page    = (int) self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->store_raw($page, $content);
        $this->log_in_without_unfiltered_html();
        $this->session = null;
        $this->mount();

        $edit   = [ 'id' => $page, 'expected_hash' => hash('sha256', $content), 'path' => [ 2 ], 'inner_html' => "\n<p>World</p>\n" ];
        $result = $this->on_both('wpmcp-update-block', $edit);

        $envelope = $this->envelope($result);
        $this->assertSame('unfiltered_html_required', $envelope['code']);
        $this->assertArrayNotHasKey('reason', $envelope);
        $this->assertSame($envelope['message'], $result['content'][0]['text']);
        $this->assertSame($content, get_post($page)->post_content);
    }

    public function test_a_dispatcher_refusal_is_a_tool_error_with_its_code_on_both_transports(): void
    {
        $this->mount();

        foreach ([ false, true ] as $modern) {
            $result   = $this->on_both('wpmcp-theme-read', [ 'operation' => 'nosuch-628' ], $modern);
            $envelope = $this->envelope($result);

            $this->assertSame('unknown_operation', $envelope['code']);
            $this->assertSame('theme', $envelope['data']['integration']);
            $this->assertIsArray($envelope['data']['operations']);
            $this->assertSame($envelope['message'], $result['content'][0]['text']);
        }

        // Dispatched through call-tool it is the same refusal.
        $dispatched = $this->on_both('wpmcp-call-tool', [ 'name' => 'wpmcp/theme-read', 'arguments' => [ 'operation' => 'nosuch-628' ] ]);
        $this->assertSame('unknown_operation', $this->envelope($dispatched)['code']);

        // A dispatcher answer that is not a refusal is still a result.
        $catalog = $this->on_both('wpmcp-theme-read', [ 'operation' => 'list-operations' ]);
        $this->assertFalse($catalog['isError']);
        $this->assertSame('theme', $catalog['structuredContent']['integration']);
    }

    public function test_a_declared_but_hidden_tool_answers_with_its_envelope_and_an_unknown_name_stays_a_protocol_error(): void
    {
        Gate::set_pro_for_tests(false);
        Governance::set_ability_toggle('wpmcp/fixture-628-governed', false);
        $this->declare(static function (Registrar $registrar): void {
            $registrar->register(self::fixture('wpmcp/fixture-628-pro', static fn (): array => [], 'pro'));
            $registrar->register(self::fixture('wpmcp/fixture-628-governed', static fn (): array => []));
            $registrar->hold_for_plugin(self::fixture('wpmcp/fixture-628-held', static fn (): array => []), 'Fixture Plugin 628');
        }, []);
        $this->mount();

        $hidden = [
            'wpmcp-fixture-628-pro'      => [ 'pro_required', 'pro_required' ],
            'wpmcp-fixture-628-governed' => [ 'governance_disabled', 'disabled_by_admin' ],
            'wpmcp-fixture-628-held'     => [ 'plugin_missing', 'plugin_missing' ],
        ];
        foreach ($hidden as $tool => [ $code, $reason ]) {
            foreach ([ false, true ] as $modern) {
                $result   = $this->on_both($tool, [], $modern);
                $envelope = $this->envelope($result);

                $this->assertSame($code, $envelope['code'], $tool);
                $this->assertSame($reason, $envelope['reason'], $tool);
                $this->assertTrue($envelope['hidden'], $tool);
                $this->assertStringStartsWith(sprintf('Unavailable (%s): ', $reason), $result['content'][0]['text']);
            }
        }

        // A name nothing declares stays a protocol error, with each route's own code.
        $this->assertSame(-32602, $this->stdio('wpmcp-fixture-628-nothing')['error']['code'] ?? null);
        [ $status, $legacy ] = $this->http('wpmcp-fixture-628-nothing');
        $this->assertArrayNotHasKey('result', $legacy);
        $this->assertSame(-32003, $legacy['error']['code'] ?? null, 'The adapter keeps its "Tool not found" for an unknown name.');
        $this->assertSame(404, $status);
        [ , $modern ] = $this->http('wpmcp-fixture-628-nothing', [], true);
        $this->assertSame(-32602, $modern['error']['code'] ?? null);
    }

    public function test_a_2025_call_without_a_live_session_stays_the_adapters_to_refuse(): void
    {
        $this->mount();

        [ $status, $body ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-list-operations', 'arguments' => [] ] ],
            [ 'Mcp-Session-Id' => 'not-a-session-628', 'MCP-Protocol-Version' => '2025-11-25' ]
        );

        $this->assertArrayNotHasKey('result', $body, (string) wp_json_encode($body));
        $this->assertSame(-32005, $body['error']['code'] ?? null);
        $this->assertSame(404, $status);

        [ , $missing ] = $this->post([ 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-list-operations', 'arguments' => [] ] ]);
        $this->assertSame(-32600, $missing['error']['code'] ?? null, 'No Mcp-Session-Id header.');
    }
}
