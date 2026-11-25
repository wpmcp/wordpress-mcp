<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Plugin;

/**
 * Calls one MCP method the way a client does, on both transports: over
 * stdio (the dispatcher `wp mcp-stdio serve` runs) and over HTTP (the
 * mounted /wp-json/mcp/wpmcp-server route, through rest_do_request(), as a
 * 2025-11-25 session or a 2026-07-28 request with its headers). Responses
 * are decoded as the client reads them. Also swaps the shared Registrar for
 * a test's own; call restore_wire() in tearDown().
 */
trait Mcp_Wire
{
    private ?string $wire_session = null;

    private ?Registrar $wire_registrar = null;

    /** @var list<string> abilities a test registered into the live registry */
    private array $wire_abilities = [];

    private function restore_wire(): void
    {
        foreach ($this->wire_abilities as $name) {
            if (wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }
        $this->wire_abilities = [];
        if (null !== $this->wire_registrar) {
            (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->wire_registrar);
            $this->wire_registrar = null;
        }
        $this->wire_session = null;
        global $wp_rest_server;
        $wp_rest_server = null;
    }

    /** Makes $registrar the plugin's own until restore_wire(). */
    private function use_registrar(Registrar $registrar): void
    {
        $property = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->wire_registrar ??= $property->getValue(Plugin::instance());
        $property->setValue(Plugin::instance(), $registrar);
    }

    /**
     * Declares abilities on $registrar in a private wp_abilities_api_init, so
     * the registered ones reach the live registry. restore_wire()
     * unregisters $names.
     *
     * @param callable(Registrar): void $declare
     * @param list<string>              $names
     */
    private function register_on(Registrar $registrar, callable $declare, array $names): void
    {
        $this->use_registrar($registrar);
        $this->wire_abilities = array_merge($this->wire_abilities, $names);
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($declare, $registrar): void {
            $declare($registrar);
        });
        do_action('wp_abilities_api_init');
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
        $this->wire_session = null;
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function post(array $message, array $headers = []): array
    {
        global $wp_rest_server;

        $request = new \WP_REST_Request('POST', '/mcp/wpmcp-server');
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

    /** The 2025-11-25 session of the current user, opened once (forgotten when the user changes). */
    private function session(): string
    {
        if (null === $this->wire_session) {
            [ $status, $body, $headers ] = $this->post([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => [ 'name' => 'wire-test', 'version' => '0' ] ],
            ]);
            $this->assertSame(200, $status, (string) wp_json_encode($body));
            $this->wire_session = (string) ($headers['Mcp-Session-Id'] ?? '');
            $this->assertNotSame('', $this->wire_session);
        }

        return $this->wire_session;
    }

    private function forget_session(): void
    {
        $this->wire_session = null;
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
     * One request over HTTP, decoded: tools/call with $params['name'] in the
     * Mcp-Name header on 2026-07-28.
     *
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function http_request(string $method, array $params = [], bool $modern = false): array
    {
        if ($modern) {
            $params['_meta'] = self::modern_meta();
            $headers         = [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method ];
            if (isset($params['name']) && is_string($params['name'])) {
                $headers['Mcp-Name'] = $params['name'];
            }
        } else {
            $headers = [ 'Mcp-Session-Id' => $this->session(), 'MCP-Protocol-Version' => '2025-11-25' ];
        }
        [ $status, $body ] = $this->post([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params ], $headers);

        return [ $status, $body ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function http(string $tool, array $arguments = [], bool $modern = false): array
    {
        return $this->http_request('tools/call', [ 'name' => $tool, 'arguments' => $arguments ], $modern);
    }

    /**
     * One request over stdio, decoded the way the client reads it.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function stdio_request(string $method, array $params = [], bool $modern = false): array
    {
        if ($modern) {
            $params['_meta'] = self::modern_meta();
        }
        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ]);

        return (array) json_decode((string) wp_json_encode($response), true);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function stdio(string $tool, array $arguments = [], bool $modern = false): array
    {
        return $this->stdio_request('tools/call', [ 'name' => $tool, 'arguments' => $arguments ], $modern);
    }

    /**
     * The same call on both transports, on one revision: the result, after
     * asserting the two are identical.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
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
}
