<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Client_App;
use WPMCP\Admin\Connection\Verdict;
use WPMCP\Admin\Connection\Wp_Activity_Store;
use WPMCP\Admin\Connection_Page;

/**
 * Issue #1258, on the real HTTP route: an app on MCP 2026-07-28 never sends
 * initialize (Claude Code 2.1.330 sends server/discover, then tools/list and
 * tools/call, each naming itself in _meta), so step 4 of the Connection
 * screen must count any request the route answered for the connection's
 * application password, not an MCP session.
 *
 * Each test makes the password with the screen's own provision action,
 * signs in with it through core (wp_validate_application_password, which
 * records the use and the uuid the REST API reports), POSTs JSON-RPC to the
 * mounted /mcp/wpmcp-server and runs the response through rest_post_dispatch
 * as WP_REST_Server::serve_request() does. Then it reads what "Check
 * connection" reads.
 */
class ConnectionActivityHttpTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';

    private int $admin = 0;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'baker' ]);
        wp_set_current_user($this->admin);
        delete_option(Connection_Page::OPTION);
        $this->mount();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server, $wp_rest_application_password_uuid, $wp_rest_application_password_status;
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        $wp_rest_application_password_uuid   = null;
        $wp_rest_application_password_status = null;
        $wp_rest_server                      = null;
        delete_option(Connection_Page::OPTION);
        parent::tearDown();
    }

    /** Same re-initialisation McpTransportMountedTest documents. */
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
        add_action('rest_api_init', [$adapter, 'init'], 15);

        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }

    /** @return array{0: string, 1: string} the plaintext and the uuid of a Claude Code connection made on the screen */
    private function connect(): array
    {
        $made = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'client'                  => 'claude-code',
            'user_id'                 => (string) $this->admin,
            'request_id'              => wp_generate_uuid4(),
        ]);
        $this->assertIsArray($made);
        $this->assertArrayNotHasKey('error', $made);

        return [ (string) $made['password'], (string) $made['uuid'] ];
    }

    /** Signs in with the password through core, as a request with a Basic header does. */
    private function sign_in(string $password): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'baker';
        $_SERVER['PHP_AUTH_PW']   = $password;
        wp_set_current_user(0);
        $user = wp_validate_application_password(false);
        $this->assertSame($this->admin, $user, 'core accepted the application password');
        wp_set_current_user($this->admin);
    }

    /**
     * @param array<string, mixed>  $message JSON-RPC message.
     * @param array<string, string> $headers Extra request headers.
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>} status, decoded body, headers
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

        $response = apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), $wp_rest_server, $request);
        $data     = json_decode((string) wp_json_encode($response->get_data()), true);

        return [ $response->get_status(), is_array($data) ? $data : [], $response->get_headers() ];
    }

    /**
     * A 2026-07-28 request as Claude Code 2.1.330 sends it: the revision,
     * the client and its capabilities in _meta, mirrored in the headers.
     *
     * @param array<string, string> $headers Overrides for the derived headers.
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function modern(string $method, array $headers = []): array
    {
        $params = [ '_meta' => [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'claude-code', 'title' => 'Claude Code', 'version' => '2.1.330' ],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ] ];

        return $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params ],
            array_merge([ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method ], $headers)
        );
    }

    private function verdict(string $uuid): Verdict
    {
        $checked = (new Connection_Page())->check(Client_App::ClaudeCode, false, $uuid, false);
        $this->assertNotNull($checked);

        return $checked['result']->verdict;
    }

    public function test_tools_list_without_initialize_records_the_connection_and_step_4_turns_connected(): void
    {
        [ $password, $uuid ] = $this->connect();
        $this->assertSame(Verdict::NotSeen, $this->verdict($uuid));

        $this->sign_in($password);
        $this->assertSame($uuid, rest_get_authenticated_app_password());
        [ $status, $body, $headers ] = $this->modern('tools/list');

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertNotEmpty($body['result']['tools'] ?? [], 'the route answered tools/list');
        $this->assertArrayNotHasKey('Mcp-Session-Id', $headers, 'no session on 2026-07-28');
        $activity = (new Wp_Activity_Store())->find($this->admin, $uuid);
        $this->assertNotNull($activity, 'tools/list without initialize is recorded for the password');
        $this->assertSame('claude-code 2.1.330', $activity->client->label());
        $this->assertSame(Verdict::Connected, $this->verdict($uuid));
    }

    public function test_an_initialize_based_client_still_reaches_connected(): void
    {
        [ $password, $uuid ] = $this->connect();
        $this->sign_in($password);

        [ $status, $body, $headers ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 0,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => [ 'name' => 'cursor', 'version' => '1.7.2' ] ],
        ]);
        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertNotEmpty($headers['Mcp-Session-Id'] ?? '', 'the 2025 lifecycle opened a session');
        $this->assertSame('cursor 1.7.2', (new Wp_Activity_Store())->find($this->admin, $uuid)?->client->label());
        $this->assertSame(Verdict::Connected, $this->verdict($uuid));

        // A later request rides the session and names nobody: the name stays.
        [ $listed ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ],
            [ 'Mcp-Session-Id' => (string) $headers['Mcp-Session-Id'], 'MCP-Protocol-Version' => '2025-11-25' ]
        );
        $this->assertSame(200, $listed);
        $this->assertSame('cursor 1.7.2', (new Wp_Activity_Store())->find($this->admin, $uuid)?->client->label());
    }

    public function test_a_request_the_route_refused_records_nothing_and_the_check_says_why(): void
    {
        [ $password, $uuid ] = $this->connect();
        $this->sign_in($password);

        [ $status ] = $this->modern('tools/list', [ 'Mcp-Method' => 'tools/call' ]);

        $this->assertSame(400, $status, 'a header mismatch is refused');
        $this->assertNull((new Wp_Activity_Store())->find($this->admin, $uuid));
        $this->assertSame(Verdict::PasswordOnly, $this->verdict($uuid), 'WordPress accepted the password, but no MCP request was answered');
    }

    public function test_a_request_without_an_application_password_records_nothing(): void
    {
        [ , $uuid ] = $this->connect();

        [ $status ] = $this->modern('tools/list');

        $this->assertSame(200, $status, 'a logged-in user without a password still gets an answer');
        $this->assertNull((new Wp_Activity_Store())->find($this->admin, $uuid));
        $this->assertSame('', get_user_meta($this->admin, is_multisite() ? Wp_Activity_Store::META . '_' . get_current_blog_id() : Wp_Activity_Store::META, true));
    }

    public function test_another_route_records_nothing(): void
    {
        [ $password, $uuid ] = $this->connect();
        $this->sign_in($password);
        global $wp_rest_server;

        $request  = new \WP_REST_Request('GET', '/wp/v2/users/me');
        $response = apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), $wp_rest_server, $request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull((new Wp_Activity_Store())->find($this->admin, $uuid));
        $this->assertSame(Verdict::PasswordOnly, $this->verdict($uuid));
    }

    public function test_revoking_the_connection_forgets_what_was_recorded(): void
    {
        [ $password, $uuid ] = $this->connect();
        $this->sign_in($password);
        $this->modern('server/discover');
        $this->assertNotNull((new Wp_Activity_Store())->find($this->admin, $uuid));

        wp_set_current_user($this->admin);
        $revoked = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'revoke',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'user_id'                 => (string) $this->admin,
            'uuid'                    => $uuid,
        ]);

        $this->assertSame('revoke', $revoked['action'] ?? null, (string) wp_json_encode($revoked));
        $this->assertNull((new Wp_Activity_Store())->find($this->admin, $uuid));
    }
}
