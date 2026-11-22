<?php

declare(strict_types=1);

namespace WPMCP\Bench;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Scenarios.php';
require_once __DIR__ . '/wire.php';

/**
 * The in-process mode of the code mode benchmark (issue #1130).
 *
 * Not part of the test gate: it lives outside tests/, so no suite picks it
 * up. bin/bench-code-mode.sh --mode=inprocess runs it through
 * bin/test-local.sh as a targeted run, which gives it the cached WordPress
 * install (WooCommerce, Elementor, Yoast SEO from the test plugins), a
 * database of its own and the harness bootstrap, and drops the database
 * when it exits.
 *
 * Each (scenario, surface) pair is one test, so the harness's transaction
 * rollback gives every replay fresh fixtures. A call goes through
 * WP_Ability::execute(), the path call-tool uses: input validation, the
 * permission chain, the rate limiter and Safe_Mutation all run. The result
 * bytes are the JSON of what execute() returned, one copy, which is what a
 * client hands the model as the text block; the MCP wire also repeats it as
 * structuredContent (HTTP mode measures that).
 *
 * The results go to WPMCP_BENCH_OUT as JSON when the class is done.
 *
 * @group bench
 */
final class BenchTest extends \WP_UnitTestCase
{
    /**
     * Collected across the tests of the class and written once by
     * wpTearDownAfterClass(): PHPUnit builds a new instance per test.
     *
     * @var array{tools_list: array<string, array{bytes: int, tools: int, source: string}>, scenarios: array<array-key, array<string, mixed>>}
     */
    private static array $results = ['tools_list' => [], 'scenarios' => []];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
        self::register_as_pro_site();
        self::$results = [
            'tools_list' => [],
            'scenarios'  => [],
        ];
    }

    /**
     * The harness boots the plugin unlicensed, so its live registry holds the
     * free tools only. The scenarios measure a licensed site, so the wpmcp
     * abilities are registered again with the Pro gate open, the way
     * Plugin::register_abilities() runs on such a site: integrations register
     * for the plugins that are really active (WooCommerce, Elementor, Yoast
     * SEO, ACF, Polylang from the test plugins), not forced on as the
     * manifest test does. The bench process exits afterwards, so nothing is
     * put back.
     */
    private static function register_as_pro_site(): void
    {
        Gate::set_pro_for_tests(true);
        foreach (array_keys(wp_get_abilities()) as $name) {
            if (str_starts_with((string) $name, 'wpmcp/')) {
                wp_unregister_ability((string) $name);
            }
        }

        $plugin = Plugin::instance();
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue($plugin, new Registrar());
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', [$plugin, 'register_abilities']);
        do_action('wp_abilities_api_init');
    }

    public static function wpTearDownAfterClass(): void
    {
        $out = (string) getenv('WPMCP_BENCH_OUT');
        if ('' === $out) {
            return;
        }

        $plugins = [];
        foreach (['WooCommerce' => 'WC_VERSION', 'Elementor' => 'ELEMENTOR_VERSION', 'Yoast SEO' => 'WPSEO_VERSION'] as $name => $const) {
            if (defined($const)) {
                $plugins[ $name ] = Data::str([constant($const)], 0);
            }
        }
        $scenarios = self::$results['scenarios'];
        ksort($scenarios);

        $doc = [
            'issue'        => 1130,
            'mode'         => 'inprocess',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'commit'       => (string) getenv('WPMCP_BENCH_COMMIT'),
            'wordpress'    => get_bloginfo('version'),
            'php'          => PHP_VERSION,
            'wpmcp'        => defined('WPMCP_VERSION') ? Data::str([constant('WPMCP_VERSION')], 0) : '',
            'site'         => 'single site, Pro licence open, wpmcp integrations registered for the active plugins',
            'plugins'      => $plugins,
            'tokens'       => Wire::token_factor(__DIR__),
            'methods'      => [
                'tools_list_bytes' => 'the JSON-RPC tools/list response, sent once per session',
                'calls'            => 'tool calls the scripted sequence makes',
                'turns'            => 'model round trips: a step whose calls are independent (a fan-out) is one turn',
                'result_bytes'     => 'JSON of each WP_Ability::execute() result, summed: what goes back to the model as text (the MCP wire repeats it as structuredContent)',
                'ms'               => 'wall time of the replay in-process, without the tools/list',
            ],
            'tools_list'   => self::$results['tools_list'],
            'scenarios'    => array_values($scenarios),
        ];

        file_put_contents($out, Data::json($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        Snapshot_Store::install();
        add_filter('wpmcp_rate_limit', static fn() => 1000000);
        if (class_exists('WC_Install')) {
            \WC_Install::create_roles();
            $GLOBALS['wp_roles'] = null;
            wp_roles();
        }
        $admin = wp_insert_user(['user_login' => 'bench_admin_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(24), 'role' => 'administrator']);
        if (! is_int($admin)) {
            throw new \RuntimeException('could not create the bench administrator: ' . $admin->get_error_message());
        }
        wp_set_current_user($admin);
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_all_filters('wpmcp_rate_limit');
        delete_option(Tool_Exposure::OPTION);
        Governance::reset_for_tests();
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function cases(): array
    {
        $only  = (int) getenv('WPMCP_BENCH_SCENARIO');
        $cases = [];
        foreach (Scenarios::all() as $scenario) {
            if (0 !== $only && $only !== $scenario->id()) {
                continue;
            }
            foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
                $cases[ sprintf('%d-%s %s', $scenario->id(), $scenario->slug(), $surface) ] = [$scenario->id(), $surface];
            }
        }
        return $cases;
    }

    public function test_tools_list_on_each_surface(): void
    {
        self::assertSame(Tool_Exposure::COMPACT_CORE, Surface::COMPACT_CORE, 'Surface::COMPACT_CORE drifted from Tool_Exposure::COMPACT_CORE');

        $dump  = (string) getenv('WPMCP_BENCH_SAVE_TOOLS_LIST');
        $bytes = [];
        foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
            $wire = Wire::tools_list($surface);
            if ('' !== $dump && is_dir($dump)) {
                file_put_contents($dump . '/tools-list-' . $surface . '.json', $wire['payload']);
            }
            self::$results['tools_list'][ $surface ] = ['bytes' => $wire['bytes'], 'tools' => $wire['tools'], 'source' => $wire['source']];
            $bytes[ $surface ]                       = $wire['bytes'];
            self::assertGreaterThan(0, $wire['tools']);
        }
        self::assertLessThan($bytes[ Surface::FULL ], $bytes[ Surface::COMPACT ]);
    }

    /**
     * @dataProvider cases
     */
    public function test_scenario(int $id, string $surface): void
    {
        $scenario = Scenarios::find($id);
        if (null === $scenario) {
            throw new \InvalidArgumentException('no scenario ' . $id);
        }
        $key   = sprintf('%02d', $id);
        $entry = self::$results['scenarios'][ $key ] ?? [
            'id'       => $id,
            'slug'     => $scenario->slug(),
            'title'    => $scenario->title(),
            'surfaces' => [],
        ];

        $missing = $scenario->missing();
        if ([] !== $missing) {
            $entry['surfaces']                   = Data::map($entry, 'surfaces') + [$surface => ['status' => 'skipped', 'reason' => 'needs ' . implode(', ', $missing)]];
            self::$results['scenarios'][ $key ] = $entry;
            self::markTestSkipped('needs ' . implode(', ', $missing));
        }

        $fixtures = $scenario->seed();
        $out      = (new Runner(self::execute(...)))->run(Scenarios::sequence($scenario, $fixtures, $surface));

        if ('ok' === $out['status']) {
            $problem = $scenario->verify($fixtures, $out['state']);
            if (null !== $problem) {
                $out['status'] = 'failed';
                $out['reason'] = 'verify: ' . $problem;
            }
        }
        unset($out['state']);
        $surfaces                            = Data::map($entry, 'surfaces');
        $surfaces[ $surface ]                = $out;
        $entry['surfaces']                   = $surfaces;
        self::$results['scenarios'][ $key ] = $entry;

        self::assertSame('ok', $out['status'], (string) $out['reason']);
    }

    /**
     * The in-process transport: the ability's own execute(), with its result
     * measured as the JSON the model would read.
     *
     * @param array<string, mixed> $args
     * @return array{value: mixed, bytes: int, error: ?string}
     */
    public static function execute(string $tool, array $args): array
    {
        $ability = wp_has_ability($tool) ? wp_get_ability($tool) : null;
        if (null === $ability) {
            return ['value' => null, 'bytes' => 0, 'error' => $tool . ' is not registered'];
        }

        try {
            $value = $ability->execute($args);
        } catch (\Throwable $e) {
            $text = $e->getMessage();
            return ['value' => null, 'bytes' => strlen($text), 'error' => $text];
        }

        if ($value instanceof \WP_Error) {
            $body = ['code' => $value->get_error_code(), 'message' => $value->get_error_message()];
            return ['value' => null, 'bytes' => strlen(Data::json($body)), 'error' => Data::str($body, 'code') . ': ' . $value->get_error_message()];
        }

        return ['value' => $value, 'bytes' => strlen(Data::json($value)), 'error' => self::result_error($value)];
    }

    /** The error a successful-looking result reports in its body, if any. */
    private static function result_error(mixed $value): ?string
    {
        if (Data::truthy($value, 'error') || [] !== Data::arr($value, 'error')) {
            $error = Data::at($value, 'error');
            if (! is_array($error)) {
                return Data::str($value, 'error');
            }
            $message = null !== Data::at($error, 'message') ? Data::str($error, 'message') : Data::json($error);
            return Data::str($error, 'code') . ': ' . $message;
        }
        $status = Data::at($value, 'status');
        if (is_int($status) && $status >= 400) {
            $body = Data::at($value, 'body') ?? $value;
            return 'HTTP ' . $status . ': ' . substr(Data::json($body), 0, 300);
        }
        return null;
    }
}
