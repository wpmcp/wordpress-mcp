<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Kernel\Request_Context;
use WPMCP\Api\V1\Value\Context;
use WPMCP\MCP\Route_Scoped_Mount;

/**
 * Decision D8: with WPMCP_ROUTE_SCOPED_MCP on, the MCP adapter is
 * instantiated only on this plugin's MCP route and under WP-CLI, so an
 * unrelated REST request registers no abilities. With the constant absent
 * (the default) the adapter mounts on every request, as before.
 *
 * The decision itself is checked in this process. Whether the adapter was
 * instantiated, and how often wp_abilities_api_init fired, can only be seen
 * in a fresh request: the suite bootstrap already instantiated the adapter
 * singleton and registered every ability, so those cases boot WordPress in
 * a child process (tests/support/probes/route-scoped-mcp.php), the way
 * ManageUpdatesFreshRequestTest does. The constant is only ever defined in
 * that child, never in the suite process.
 */
class ServerRouteScopeTest extends \WP_UnitTestCase
{
    public function test_the_flag_is_off_in_the_suite(): void
    {
        $this->assertFalse(
            defined('WPMCP_ROUTE_SCOPED_MCP'),
            'A process-wide WPMCP_ROUTE_SCOPED_MCP would change every in-suite REST test; define it only in a child process.'
        );
    }

    /**
     * @return array<string, array{0: Request_Context, 1: bool}>
     */
    public static function contexts(): array
    {
        return [
            'front end'                    => [new Request_Context(uri: '/sample-page/'), false],
            'wp-admin'                     => [new Request_Context(uri: '/wp-admin/', is_admin: true), false],
            'unrelated REST route'         => [new Request_Context(uri: '/wp-json/wp/v2/posts'), false],
            'REST index'                   => [new Request_Context(uri: '/wp-json/'), false],
            'similar REST namespace'       => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server-other'), false],
            'unrelated rest_route'         => [new Request_Context(uri: '/', query: ['rest_route' => '/wp/v2/posts']), false],
            'ajax'                         => [new Request_Context(uri: '/wp-admin/admin-ajax.php', doing_ajax: true, is_admin: true), false],
            'cron'                         => [new Request_Context(uri: '/wp-cron.php', doing_cron: true), false],
            'MCP route, pretty'            => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server'), true],
            'MCP route, trailing slash'    => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server/'), true],
            'MCP route, query string'      => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server?x=1'), true],
            'MCP server card'              => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server/server-card'), true],
            'MCP route, rest_route'        => [new Request_Context(uri: '/', query: ['rest_route' => '/mcp/wpmcp-server']), true],
            'MCP route, subdirectory'      => [new Request_Context(uri: '/blog/wp-json/mcp/wpmcp-server', home_path: '/blog'), true],
            'MCP route, filtered prefix'   => [new Request_Context(uri: '/api/mcp/wpmcp-server', rest_prefix: 'api'), true],
            'default prefix when filtered' => [new Request_Context(uri: '/wp-json/mcp/wpmcp-server', rest_prefix: 'api'), false],
            'WP-CLI'                       => [new Request_Context(is_cli: true), true],
            'MCP route, PATHINFO'          => [new Request_Context(uri: '/index.php/wp-json/mcp/wpmcp-server'), true],
            'MCP route, other case'        => [new Request_Context(uri: '/wp-json/MCP/WPMCP-Server'), true],
        ];
    }

    /**
     * @dataProvider contexts
     */
    public function test_a_route_scoped_mount_serves_only_the_mcp_route_and_wp_cli(Request_Context $context, bool $mounts): void
    {
        $this->assertSame($mounts, Route_Scoped_Mount::serves($context));
    }

    public function test_without_the_flag_the_request_is_never_read(): void
    {
        $read  = false;
        $mount = new Route_Scoped_Mount(false);

        $this->assertTrue($mount->mounts(static function () use (&$read): Request_Context {
            $read = true;
            return new Request_Context(uri: '/sample-page/');
        }));
        $this->assertFalse($read, 'With the flag off boot() must not read the request');
    }

    public function test_with_the_flag_the_request_decides(): void
    {
        $mount = new Route_Scoped_Mount(true);

        $this->assertFalse($mount->mounts(static fn (): Request_Context => new Request_Context(uri: '/sample-page/')));
        $this->assertTrue($mount->mounts(static fn (): Request_Context => new Request_Context(uri: '/wp-json/mcp/wpmcp-server')));
    }

    public function test_the_flag_reads_the_constant(): void
    {
        $this->assertFalse(Route_Scoped_Mount::enabled_by_constant(), 'The constant is not defined in the suite process');
    }

    public function test_a_rest_route_in_a_form_post_body_is_read(): void
    {
        $get  = $_GET;
        $post = $_POST;
        $uri  = $_SERVER['REQUEST_URI'] ?? null;
        try {
            $_GET                   = [];
            $_POST                  = ['rest_route' => '/mcp/wpmcp-server'];
            $_SERVER['REQUEST_URI'] = '/';
            $this->assertSame(Context::Mcp, Request_Context::from_globals(['rest_route' => '/mcp/wpmcp-server'])->context(), 'WordPress reads rest_route from the POST body too');
            $this->assertSame(Context::Frontend, Request_Context::from_globals()->context(), 'The body is read from the request, not from $_POST');

            $this->assertSame(Context::Frontend, Request_Context::from_globals(['rest_route' => ['not', 'a', 'string']])->context(), 'A non-string rest_route is ignored');
        } finally {
            $_GET  = $get;
            $_POST = $post;
            if (null === $uri) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $uri;
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string, 3: bool, 4: bool}>
     *         uri, flag, rest_route ("-" for none), WP-CLI, adapter expected
     */
    public static function requests(): array
    {
        return [
            'flag off, front end'                => ['/sample-page/', false, '-', false, true],
            'flag off, unrelated REST route'     => ['/wp-json/wp/v2/posts', false, '-', false, true],
            'flag on, front end'                 => ['/sample-page/', true, '-', false, false],
            'flag on, unrelated REST route'      => ['/wp-json/wp/v2/posts', true, '-', false, false],
            'flag on, MCP route, pretty'         => ['/wp-json/mcp/wpmcp-server', true, '-', false, true],
            'flag on, MCP route, rest_route'     => ['/', true, '/mcp/wpmcp-server', false, true],
            'flag on, MCP route, PATHINFO'       => ['/index.php/wp-json/mcp/wpmcp-server', true, '-', false, true],
            'flag on, WP-CLI'                    => ['/', true, '-', true, true],
        ];
    }

    /**
     * @dataProvider requests
     */
    public function test_the_adapter_is_instantiated_only_where_the_flag_allows(string $uri, bool $flag, string $rest_route, bool $cli, bool $adapter): void
    {
        $result = $this->probe($uri, $flag, $rest_route, $cli);

        $this->assertSame($adapter, $result['adapter'], 'Whether McpAdapter::instance() ran while WordPress booted');

        if ($adapter) {
            // Mounted: building the REST server reaches create_server(),
            // which reads wp_get_abilities() and publishes the route.
            $this->assertGreaterThanOrEqual(1, $result['abilities_init'], 'Mounting registers the abilities');
            $this->assertTrue($result['route'], 'The MCP route is in the REST route table');
        } else {
            // The point of D8: nothing registers an ability on this request.
            $this->assertSame(0, $result['abilities_init'], 'No ability may be registered on a request that is not for MCP');
            $this->assertFalse($result['route'], 'The MCP route is not mounted on an unrelated request');
        }
    }

    /**
     * @return array{adapter: bool, abilities_init: int|null, route: bool, error: string|null}
     */
    private function probe(string $uri, bool $flag, string $rest_route, bool $cli): array
    {
        if (is_multisite()) {
            $this->markTestSkipped('The probe boots the single-site test install.');
        }
        $config = getenv('WP_TESTS_CONFIG_FILE_PATH');
        if (! $config) {
            $config = rtrim((string) getenv('WP_TESTS_DIR'), '/') . '/wp-tests-config.php';
        }
        if (! is_readable($config)) {
            $this->markTestSkipped('No wp-tests-config.php to boot a fresh WordPress from.');
        }

        $root  = dirname(__DIR__, 3);
        $probe = $root . '/tests/support/probes/route-scoped-mcp.php';
        $cmd   = sprintf(
            '%s %s %s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe),
            escapeshellarg($config),
            escapeshellarg($root),
            escapeshellarg($uri),
            $flag ? '1' : '0',
            escapeshellarg($rest_route),
            $cli ? '1' : '0'
        );

        $output = (string) shell_exec($cmd);
        $json   = substr($output, (int) strrpos($output, '{"adapter"'));
        $result = json_decode($json, true);

        $this->assertIsArray($result, 'The probe must print its result: ' . $output);
        $this->assertNull($result['error'], 'The probe failed: ' . (string) $result['error']);

        return $result;
    }
}
