<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Connection_Assets;
use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Connect\Client_Config_Generator;
use WPMCP\Connect\Exposure;

/**
 * Issue #1260: every Copy button on the Connection screen copies the real,
 * working text, whether the password is hidden on screen (the default after
 * Create connection) or shown, and its confirmation says what it copied.
 *
 * Each case is the real screen, drawn by WordPress for one app: created over
 * AJAX (the script draws the answer, as in a browser with JavaScript) or by
 * the form post (the script runs on the page that comes back). The text each
 * button must copy comes from Client_Config_Generator and the password
 * WordPress stored, not from the views.
 *
 * The first test reads the copy source of every button in the HTML. The
 * second runs the real connection.js on each case in jsdom
 * (tests/js/connection-copy.test.mjs, node --test) and clicks every button
 * with the password hidden and shown; it needs Node.js and, once per
 * checkout, npm ci --prefix tests/js, and skips without them.
 *
 * @group ajax
 */
class ConnectionCopyTest extends \WP_Ajax_UnitTestCase
{
    private const JS_TEST = 'tests/js/connection-copy.test.mjs';

    /** What each button announces once it copied, by the id of the text it copies. */
    private const COPIED = [
        'wpmcp-password'                 => 'Copied the application password.',
        'wpmcp-snippet-claude-code'      => 'Copied the Claude Code command, with the password.',
        'wpmcp-snippet-claude-code-json' => 'Copied the .mcp.json block, with the password.',
        'wpmcp-snippet-claude-desktop'   => 'Copied the Claude Desktop config, with the password.',
        'wpmcp-snippet-cursor'           => 'Copied the Cursor config, with the password.',
        'wpmcp-snippet-antigravity'      => 'Copied the Antigravity config, with the password.',
        'wpmcp-snippet-vscode'           => 'Copied the VS Code config, with the password.',
        'wpmcp-snippet-endpoint'         => 'Copied the address.',
        'wpmcp-snippet-header'           => 'Copied the header, with the password.',
        'wpmcp-snippet-generic'          => 'Copied the JSON block, with the password.',
        'wpmcp-snippet-url'              => 'Copied the MCP server address.',
        'wpmcp-endpoint'                 => 'Copied the endpoint address.',
    ];

    private const PASSWORD_APPS = [ 'claude-code', 'claude-desktop', 'cursor', 'antigravity', 'other' ];

    private int $admin = 0;

    private string $fixtures = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'baker' ]);
        wp_set_current_user($this->admin);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('pre_http_request', [ $this, 'stub_http' ], 10, 3);
        foreach ([ Connection_Page::OPTION, Connection_Page::SELF_TEST_OPTION, OAuth_Config::OPTION, Exposure::OPTION ] as $option) {
            delete_option($option);
        }
    }

    protected function tearDown(): void
    {
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('pre_http_request', [ $this, 'stub_http' ], 10);
        remove_filter('home_url', [ $this, 'public_home' ]);
        remove_all_filters('wpmcp_oauth_enabled');
        foreach ([ Connection_Page::OPTION, Connection_Page::SELF_TEST_OPTION, OAuth_Config::OPTION, Exposure::OPTION ] as $option) {
            delete_option($option);
        }
        wp_dequeue_script(Connection_Assets::HANDLE);
        wp_deregister_script(Connection_Assets::HANDLE);
        wp_dequeue_style(Connection_Assets::HANDLE);
        wp_deregister_style(Connection_Assets::HANDLE);
        if ('' !== $this->fixtures) {
            array_map('unlink', (array) glob($this->fixtures . '/*.json'));
            @rmdir($this->fixtures);
        }
        $_POST = [];
        $_GET  = [];
        parent::tearDown();
    }

    public function test_every_copy_button_holds_the_text_it_copies_and_what_it_says_once_copied(): void
    {
        foreach ($this->cases() as $case) {
            $html    = $case['page'] . (null === $case['create'] ? '' : (string) $case['create']['data']['html']);
            $buttons = $this->buttons($html);
            $targets = array_column($case['copies'], 'target');
            sort($targets);
            $found = array_keys($buttons);
            sort($found);
            $this->assertSame($targets, $found, $case['name'] . ': the Copy buttons on the screen');

            foreach ($case['copies'] as $want) {
                $button = $buttons[ $want['target'] ];
                $this->assertSame($want['text'], $button['data-wpmcp-copy-text'] ?? null, $case['name'] . ': what ' . $want['target'] . ' copies');
                $this->assertSame($want['copied'], $button['data-wpmcp-copied'] ?? null, $case['name'] . ': what ' . $want['target'] . ' says once copied');
            }
        }
    }

    public function test_copy_gives_the_same_working_text_with_the_password_hidden_or_shown(): void
    {
        $node = $this->node();

        $this->fixtures = rtrim(get_temp_dir(), '/') . '/wpmcp-copy-' . wp_generate_password(12, false);
        $this->assertTrue(wp_mkdir_p($this->fixtures));
        // node:test counts every test and subtest: the fixtures check, then
        // per case the case, its coverage, by-hand and exposure checks, one
        // per Copy button, and the label check where asked.
        $tests = 1;
        foreach ($this->cases() as $index => $case) {
            file_put_contents(sprintf('%s/%02d.json', $this->fixtures, $index), (string) wp_json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $tests += 5 + count($case['copies']) + ($case['labels'] ? 1 : 0);
        }

        $env     = array_merge(getenv(), [ 'WPMCP_JS_FIXTURES' => $this->fixtures ]);
        $command = [ $node, '--test', '--test-reporter=spec', '--test-timeout=60000', '--test-force-exit', self::JS_TEST ];
        $process = proc_open($command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes, $this->root(), $env);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        $this->assertSame(0, $code, 'node --test ' . self::JS_TEST . " failed:\n" . $output);
        foreach ([ 'tests' => $tests, 'pass' => $tests, 'fail' => 0, 'skipped' => 0 ] as $count => $expected) {
            $this->assertSame(1, preg_match('/^\S*\s*' . $count . ' (\d+)$/m', $output, $m), $count . " count in:\n" . $output);
            $this->assertSame($expected, (int) $m[1], $count . " in:\n" . $output);
        }
    }

    /**
     * Every case: the page the script starts on, the AJAX create answer when
     * the page creates over AJAX, the inline settings, and every Copy button
     * with what it must copy and say.
     *
     * @return list<array<string, mixed>>
     */
    private function cases(): array
    {
        $settings = $this->settings();
        $cases    = [];
        foreach (self::PASSWORD_APPS as $client) {
            $url  = admin_url('admin.php?page=' . Connection_Page::SLUG . '&client=' . $client);
            $page = $this->render([ 'client' => $client ]);
            $this->assertStringContainsString('data-wpmcp-create', $page, $client . ': the page offers Create connection');
            $answer = $this->ajax('wpmcp_connection_create', [ '_wpnonce' => $this->nonce(), 'client' => $client, 'request_id' => wp_generate_uuid4(), 'user_id' => (string) $this->admin ]);
            $this->assertTrue($answer['success'] ?? false, $client . ': ' . wp_json_encode($answer));
            $cases[] = $this->password_case($client . ', created over AJAX', 'create', $client, $url, $page, $answer, $settings, (string) $answer['data']['html']);

            $page    = $this->render([ 'client' => $client ], $this->provision($client));
            $cases[] = $this->password_case($client . ', created by the form', 'load', $client, $url, $page, null, $settings, $page);
        }
        $cases[0]['labels'] = true;

        // The OAuth apps copy only the address; they need a public HTTPS site and OAuth on.
        OAuth_Config::set_enabled(true);
        add_filter('home_url', [ $this, 'public_home' ]);
        foreach ([ 'chatgpt' => [ 'client' => 'chatgpt' ], 'claude-connector' => [ 'client' => 'claude-desktop', 'route' => 'connector' ] ] as $name => $get) {
            $endpoint = Client_Config_Generator::endpoint();
            $cases[]  = [
                'name'     => $name . ', OAuth',
                'mode'     => 'load',
                'url'      => add_query_arg($get, admin_url('admin.php?page=' . Connection_Page::SLUG)),
                'page'     => $this->render($get),
                'create'   => null,
                'settings' => $settings,
                'copies'   => [ $this->want('wpmcp-snippet-url', $endpoint, false), $this->want('wpmcp-endpoint', $endpoint, false) ],
                'token'    => '',
                'password' => '',
                'labels'   => false,
            ];
        }
        remove_filter('home_url', [ $this, 'public_home' ]);

        return $cases;
    }

    /**
     * A password app's case, with the password read from the one-time box
     * and checked against what WordPress stored.
     *
     * @param array<string, mixed>|null $answer
     * @return array<string, mixed>
     */
    private function password_case(string $name, string $mode, string $client, string $url, string $page, ?array $answer, string $settings, string $drawn): array
    {
        $this->assertSame(1, preg_match('#<output class="wpmcp-key__value" id="wpmcp-password"[^>]*>([^<]+)</output>#', $drawn, $m), $name . ': the one-time password box');
        $shown    = html_entity_decode($m[1], ENT_QUOTES);
        $password = str_replace(' ', '', $shown);
        $records  = get_option(Connection_Page::OPTION);
        $record   = end($records);
        $item     = \WP_Application_Passwords::get_user_application_password($this->admin, (string) $record['uuid']);
        $this->assertTrue(\WP_Application_Passwords::check_password($password, (string) $item['password']), $name . ': the password WordPress stored');

        return [
            'name'     => $name,
            'mode'     => $mode,
            'url'      => $url,
            'page'     => $page,
            'create'   => $answer,
            'settings' => $settings,
            'copies'   => $this->expected($client, $password),
            'token'    => base64_encode('baker:' . $password),
            'password' => $shown,
            'labels'   => false,
        ];
    }

    /**
     * Every Copy button a password app's screen shows once the connection is
     * made, with the text it must copy.
     *
     * @return list<array{target: string, text: string, copied: string, secret: bool}>
     */
    private function expected(string $client, string $password): array
    {
        $configs  = (new Client_Config_Generator())->configs('baker', $password);
        $endpoint = Client_Config_Generator::endpoint();
        $copies   = [ $this->want('wpmcp-password', trim(chunk_split($password, 4, ' ')), false) ];
        switch ($client) {
            case 'claude-code':
                $copies[] = $this->want('wpmcp-snippet-claude-code', (string) $configs['claude-code']['command'], true);
                $copies[] = $this->want('wpmcp-snippet-claude-code-json', $configs['claude-code']['snippet'], true);
                break;
            case 'claude-desktop':
                $copies[] = $this->want('wpmcp-snippet-claude-desktop', $configs['claude-desktop']['snippet'], true);
                break;
            case 'cursor':
                $copies[] = $this->want('wpmcp-snippet-cursor', $configs['cursor']['snippet'], true);
                break;
            case 'antigravity':
                $copies[] = $this->want('wpmcp-snippet-antigravity', $configs['antigravity']['snippet'], true);
                break;
            default:
                $copies[] = $this->want('wpmcp-snippet-vscode', $configs['vscode']['snippet'], true);
                $copies[] = $this->want('wpmcp-snippet-endpoint', $endpoint, false);
                $copies[] = $this->want('wpmcp-snippet-header', 'Authorization: ' . Client_Config_Generator::auth_header('baker', $password), true);
                $copies[] = $this->want('wpmcp-snippet-generic', $configs['generic']['snippet'], true);
        }
        $copies[] = $this->want('wpmcp-endpoint', $endpoint, false);

        return $copies;
    }

    /** @return array{target: string, text: string, copied: string, secret: bool} */
    private function want(string $target, string $text, bool $secret): array
    {
        return [ 'target' => $target, 'text' => $text, 'copied' => self::COPIED[ $target ], 'secret' => $secret ];
    }

    /**
     * The attributes of every Copy button in the HTML, by the id of the text
     * it copies.
     *
     * @return array<string, array<string, string>>
     */
    private function buttons(string $html): array
    {
        preg_match_all('#<button\b[^>]*\bclass="[^"]*\bwpmcp-copy\b[^"]*"[^>]*>#', $html, $tags);
        $buttons = [];
        foreach ($tags[0] as $tag) {
            preg_match_all('#\s([a-z-]+)="([^"]*)"#', $tag, $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as [ , $name, $value ]) {
                $attributes[ $name ] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            }
            $this->assertArrayHasKey('data-copy-target', $attributes, $tag);
            $buttons[ $attributes['data-copy-target'] ] = $attributes;
        }

        return $buttons;
    }

    /** The inline settings the screen's script loads with. */
    private function settings(): string
    {
        (new Connection_Assets())->enqueue('wpmcp_page_' . Connection_Page::SLUG);

        return implode("\n", (array) wp_scripts()->get_data(Connection_Assets::HANDLE, 'before'));
    }

    /** Node.js, or a skip when it or the test dependencies are missing. */
    private function node(): string
    {
        $node = (string) (getenv('WPMCP_NODE') ?: 'node');
        $out  = [];
        $code = 1;
        exec(escapeshellarg($node) . ' --version 2>/dev/null', $out, $code);
        if (0 !== $code) {
            $this->markTestSkipped('Node.js is needed to run ' . self::JS_TEST . ' (set WPMCP_NODE to its path if it is not on PATH).');
        }
        if (! is_file($this->root() . '/tests/js/node_modules/jsdom/package.json')) {
            $this->markTestSkipped('jsdom is missing: run npm ci --prefix tests/js once in this checkout.');
        }

        return $node;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private function render(array $get, array $post = []): string
    {
        $_GET  = $get;
        $_POST = $post;
        set_current_screen('admin_page_' . Connection_Page::SLUG);
        ob_start();
        (new Connection_Page())->render();
        $html  = (string) ob_get_clean();
        $_GET  = [];
        $_POST = [];

        return $html;
    }

    /**
     * @param array<string, string> $post
     * @return array<string, mixed>
     */
    private function ajax(string $action, array $post): array
    {
        $_POST = $post;
        try {
            $this->_handleAjax($action);
        } catch (\WPAjaxDieContinueException $e) {
            unset($e);
        } catch (\WPAjaxDieStopException $e) {
            unset($e);
        }
        $json                 = json_decode($this->_last_response, true);
        $this->_last_response = '';
        $_POST                = [];

        return is_array($json) ? $json : [];
    }

    /** @return array<string, string> */
    private function provision(string $client): array
    {
        return [
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => $this->nonce(),
            'client'                  => $client,
            'user_id'                 => (string) $this->admin,
            'request_id'              => wp_generate_uuid4(),
        ];
    }

    private function nonce(): string
    {
        return wp_create_nonce(Connection_Page::NONCE_ACTION);
    }

    public function public_home(string $url): string
    {
        return (string) preg_replace('#^https?://[^/]+#', 'https://example.com', $url);
    }

    /**
     * The admin-ajax cycle fires admin_init, and with it core's update
     * checks; answer those with an empty 200 (see ConnectionAjaxTest).
     *
     * @param mixed $preempt
     * @param array<string, mixed> $args
     * @return mixed
     */
    public function stub_http($preempt, $args, string $url)
    {
        if (false !== strpos($url, 'api.wordpress.org')) {
            return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
        }

        return $preempt;
    }
}
