<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Admin\Connection_Page;
use WPMCP\Connect\Client_Config_Generator;
use WPMCP\Tools\Connect\Get_Connection_Info;

/**
 * Issue #1149: ChatGPT and the Claude custom connector connect from the
 * internet, so they need a public HTTPS address. When the endpoint is not
 * one, both get-connection-info entries say so and still show the URL. The
 * Connection screen says it once, where it matters: a flag on the ChatGPT
 * card, and the full warning in step 2 of the cloud app the reader picked.
 */
class PublicAddressWarningTest extends \WP_UnitTestCase
{
    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('home_url');
        parent::tearDown();
    }

    private function site_at(string $home): void
    {
        $this->home = $home;
        remove_all_filters('home_url');
        add_filter('home_url', fn (string $url, string $path): string => rtrim($this->home, '/') . '/' . ltrim($path, '/'), 10, 2);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'plain http'        => [ 'http://example.com/wp-json/mcp/wpmcp-server', true ],
            'localhost'         => [ 'https://localhost:8080/wp-json/mcp/wpmcp-server', true ],
            'sub.localhost'     => [ 'https://shop.localhost/wp-json/mcp/wpmcp-server', true ],
            'dot test'          => [ 'https://mysite.test/wp-json/mcp/wpmcp-server', true ],
            'dot local'         => [ 'https://mysite.local/wp-json/mcp/wpmcp-server', true ],
            'single label host' => [ 'https://intranet/wp-json/mcp/wpmcp-server', true ],
            'loopback ipv4'     => [ 'https://127.0.0.1/wp-json/mcp/wpmcp-server', true ],
            'private ipv4 10'   => [ 'https://10.1.2.3/wp-json/mcp/wpmcp-server', true ],
            'private ipv4 192'  => [ 'https://192.168.1.20:8443/wp-json/mcp/wpmcp-server', true ],
            'private ipv4 172'  => [ 'https://172.16.0.5/wp-json/mcp/wpmcp-server', true ],
            'loopback ipv6'     => [ 'https://[::1]/wp-json/mcp/wpmcp-server', true ],
            'private ipv6'      => [ 'https://[fd12:3456:789a::1]/wp-json/mcp/wpmcp-server', true ],
            'link local ipv6'   => [ 'https://[fe80::1]/wp-json/mcp/wpmcp-server', true ],
            'public https'      => [ 'https://example.com/wp-json/mcp/wpmcp-server', false ],
            'public https sub'  => [ 'https://www.example.co.uk/blog/wp-json/mcp/wpmcp-server', false ],
            'public ipv4'       => [ 'https://93.184.216.34/wp-json/mcp/wpmcp-server', false ],
            'public ipv6'       => [ 'https://[2606:2800:220:1:248:1893:25c8:1946]/wp-json/mcp/wpmcp-server', false ],
        ];
    }

    /** @dataProvider addresses */
    public function test_address_checks(string $url, bool $warns): void
    {
        $this->assertSame($warns, null !== Client_Config_Generator::public_address_warning($url), $url);
    }

    public function test_a_local_http_site_warns_on_both_entries_and_keeps_the_url(): void
    {
        $this->site_at('http://localhost:8080');

        foreach ([ Client_Config_Generator::custom_connector(), Client_Config_Generator::chatgpt() ] as $entry) {
            $this->assertNotNull($entry['public_address_warning']);
            $this->assertStringContainsString('public HTTPS', $entry['public_address_warning']);
            $this->assertSame('http://localhost:8080/wp-json/mcp/wpmcp-server', $entry['url']);
        }

        $info = (new Get_Connection_Info())->handle([]);
        $this->assertSame(Client_Config_Generator::chatgpt()['public_address_warning'], $info['clients']['chatgpt']['public_address_warning']);
        $this->assertNotNull($info['clients']['custom-connector']['public_address_warning']);

        $picker = $this->render([]);
        $this->assertSame(0, substr_count($picker, 'wpmcp-public-address-warning'), 'no warning before a cloud app is picked');
        $this->assertSame(1, substr_count($picker, 'wpmcp-public-address-flag'), 'the ChatGPT card carries a flag');

        foreach ([ [ 'client' => 'chatgpt' ], [ 'client' => 'claude-desktop', 'route' => 'connector' ] ] as $get) {
            $html = $this->render($get);
            $this->assertSame(1, substr_count($html, 'wpmcp-public-address-warning'), $get['client'] . ' warns once');
            $this->assertStringContainsString('http://localhost:8080/wp-json/mcp/wpmcp-server', $html);
            $this->assertStringContainsString('data-step="create" data-state="blocked"', $html);
        }

        $local = $this->render([ 'client' => 'claude-code' ]);
        $this->assertStringNotContainsString('wpmcp-public-address-warning', $local, 'an app on this computer reaches a local site');
    }

    /** @param array<string, string> $get */
    private function render(array $get): string
    {
        $_GET = $get;
        set_current_screen('admin_page_' . Connection_Page::SLUG);
        ob_start();
        (new Connection_Page())->render();
        $_GET = [];

        return (string) ob_get_clean();
    }

    public function test_a_public_https_site_shows_no_warning(): void
    {
        $this->site_at('https://example.com');

        $this->assertNull(Client_Config_Generator::custom_connector()['public_address_warning']);
        $this->assertNull(Client_Config_Generator::chatgpt()['public_address_warning']);
        $info = (new Get_Connection_Info())->handle([]);
        $this->assertNull($info['clients']['chatgpt']['public_address_warning']);
        $this->assertNull($info['clients']['custom-connector']['public_address_warning']);

        foreach ([ [], [ 'client' => 'chatgpt' ] ] as $get) {
            $html = $this->render($get);
            $this->assertStringNotContainsString('wpmcp-public-address-warning', $html);
            $this->assertStringNotContainsString('wpmcp-public-address-flag', $html);
        }
    }
}
