<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Api\V1\Value\Context;
use WPMCP\Kernel\Request_Context;

final class RequestContextTest extends TestCase
{
    /** @return iterable<string, array{Context, array<string, mixed>}> */
    public static function requests(): iterable
    {
        yield 'cli' => [Context::Cli, ['cli' => true]];
        yield 'cron' => [Context::Cron, ['cron' => true]];
        yield 'ajax' => [Context::Ajax, ['ajax' => true, 'admin' => true]];
        yield 'admin' => [Context::Admin, ['admin' => true, 'uri' => '/wp-admin/index.php']];
        yield 'pretty rest' => [Context::Rest, ['uri' => '/wp-json/wp/v2/posts?per_page=1']];
        yield 'plain rest' => [Context::Rest, ['uri' => '/index.php', 'get' => ['rest_route' => '/wp/v2/posts']]];
        yield 'pretty mcp' => [Context::Mcp, ['uri' => '/wp-json/mcp/wpmcp-server']];
        yield 'mcp with a query' => [Context::Mcp, ['uri' => '/wp-json/mcp/wpmcp-server?x=1']];
        yield 'plain mcp' => [Context::Mcp, ['uri' => '/', 'get' => ['rest_route' => '/mcp/wpmcp-server']]];
        yield 'filtered prefix' => [Context::Mcp, ['uri' => '/api/mcp/wpmcp-server', 'prefix' => 'api']];
        yield 'subdirectory install' => [Context::Rest, ['uri' => '/blog/wp-json/wp/v2/posts', 'home_path' => '/blog']];
        yield 'similar route is not mcp' => [Context::Rest, ['uri' => '/wp-json/mcp/wpmcp-server-other']];
        yield 'pathinfo permalinks mcp' => [Context::Mcp, ['uri' => '/index.php/wp-json/mcp/wpmcp-server']];
        yield 'pathinfo permalinks rest' => [Context::Rest, ['uri' => '/index.php/wp-json/wp/v2/posts']];
        yield 'pathinfo permalinks in a subdirectory' => [Context::Mcp, ['uri' => '/blog/index.php/wp-json/mcp/wpmcp-server', 'home_path' => '/blog']];
        yield 'pathinfo front end' => [Context::Frontend, ['uri' => '/index.php/hello-world/']];
        yield 'mcp route in another case' => [Context::Mcp, ['uri' => '/wp-json/MCP/WPMCP-Server']];
        yield 'plain mcp route in another case' => [Context::Mcp, ['uri' => '/', 'get' => ['rest_route' => '/Mcp/Wpmcp-Server/']]];
        yield 'prefix in another case is not rest' => [Context::Frontend, ['uri' => '/WP-JSON/mcp/wpmcp-server']];
        yield 'front end' => [Context::Frontend, ['uri' => '/hello-world/']];
        yield 'front end with a wp-json word in a slug' => [Context::Frontend, ['uri' => '/about-wp-json/']];
    }

    /**
     * @dataProvider requests
     * @param array<string, mixed> $in
     */
    public function test_detects_the_request_context(Context $expected, array $in): void
    {
        $context = new Request_Context(
            uri: (string) ($in['uri'] ?? '/'),
            query: (array) ($in['get'] ?? []),
            is_cli: (bool) ($in['cli'] ?? false),
            doing_cron: (bool) ($in['cron'] ?? false),
            doing_ajax: (bool) ($in['ajax'] ?? false),
            is_admin: (bool) ($in['admin'] ?? false),
            rest_prefix: (string) ($in['prefix'] ?? 'wp-json'),
            home_path: (string) ($in['home_path'] ?? ''),
        );

        self::assertSame($expected, $context->context());
    }

    public function test_mcp_counts_as_rest_for_matching(): void
    {
        $mcp = new Request_Context(uri: '/wp-json/mcp/wpmcp-server');

        self::assertTrue($mcp->matches([Context::Rest]));
        self::assertTrue($mcp->matches([Context::Mcp]));
        self::assertTrue($mcp->matches([]), 'no contexts means every context');
        self::assertFalse($mcp->matches([Context::Frontend, Context::Admin]));
        self::assertTrue($mcp->matches(['rest']), 'compiled registries carry the string values');
    }

    public function test_rest_does_not_match_mcp_only(): void
    {
        self::assertFalse((new Request_Context(uri: '/wp-json/wp/v2/posts'))->matches([Context::Mcp]));
    }
}
