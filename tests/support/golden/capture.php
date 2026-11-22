<?php

declare(strict_types=1);

namespace WPMCP\Tests\Support\Golden;

/**
 * Golden capture of the legacy registration path (module architecture,
 * phase 0; handoff/2026-11-22/module-architecture.md sections 2.4, 2.6, 6).
 *
 * Two halves:
 *
 *  - files() runs in the PHPUnit process (tests/golden/GoldenCaptureTest.php).
 *    It stages the wporg tree, then starts tests/support/golden/probe.php once
 *    per flavor and request context, one process at a time, and turns what
 *    the probes report into one pretty JSON text per flavor and concern.
 *  - Everything else runs inside a probe: a fresh PHP process that defines
 *    the request context's constants, loads the canonical test environment
 *    (tests/bootstrap.php, with WP_TESTS_SKIP_INSTALL=1 so the database the
 *    parent run uses is never reinstalled) and records the plugin while it
 *    loads and after WordPress has booted.
 *
 * A fresh process per context is the only faithful way to see boot():
 * WP_ADMIN, DOING_AJAX, DOING_CRON and WP_CLI are constants set before
 * WordPress loads, and calling boot() a second time in a booted process
 * hides every static callback that is already hooked.
 *
 * Contexts. admin, ajax, cron and cli define what wp-admin/admin.php,
 * admin-ajax.php, wp-cron.php and WP-CLI define before WordPress loads
 * (WP_ADMIN, DOING_AJAX, DOING_CRON, WP_CLI; cli also gets the stub in
 * wp-cli-stub.php, which records commands instead of running them). rest
 * only serves a /wp-json/ REQUEST_URI: core defines REST_REQUEST at
 * parse_request, after plugins load, so boot() never sees it. Every context
 * serves its REQUEST_URI while the plugin loads.
 *
 * What "hooks" covers: the $wp_filter diff around `require wpmcp.php` at
 * muplugins_loaded, so the main file's whole load (the Freemius SDK,
 * GitHub_Releases::register(), the adapter guard, Plugin::boot()), not
 * boot() alone, and not hooks added later in the request.
 *
 * Flavors. full: the checkout with the Pro tier forced (Gate) before the
 * plugin loads. woocommerce: the same tree with
 * Plugin::set_flavor_for_tests('woocommerce'), which reproduces the
 * runtime group gate, not the pruned files or the rewritten text domain of
 * scripts/build-woo-release.sh. wporg: a stage built by stage_wporg() with
 * the same copy and strip.php steps as scripts/build-wporg-release.sh, loaded
 * as the plugin with \WPMCP\ resolving only to the stage's src/; third-party
 * vendor code (the MCP adapter) still comes from the checkout's vendor/,
 * because the stage gets no composer install, and the build's later gates
 * (composer, zip, Plugin Check) are not run. The checkout's own wpmcp.php,
 * which tests/bootstrap.php requires afterwards, defers to the loaded copy.
 *
 * Side effects of a probe on the run's database: the WordPress test
 * bootstrap deletes all posts (as it does for every PHPUnit run) and
 * Plugin::boot() runs Default_Seeder::seed() as on every request; the
 * capture itself writes nothing (exposure mode and the admin user are read
 * through filters and existing rows).
 *
 * Output is deterministic: registration order is kept where it is
 * meaningful (abilities, wire tools, hooks within a priority, opt-in groups,
 * refreshers), everything else is sorted, and callbacks are named by a
 * stable identity ('Class::method', 'Class->method', 'closure@file:line').
 */
final class Capture
{
    public const FLAVORS = [ 'full', 'woocommerce', 'wporg' ];

    public const CONTEXTS = [ 'frontend', 'admin', 'ajax', 'cron', 'rest', 'cli' ];

    public const CONCERNS = [
        'abilities',
        'tools',
        'held',
        'dispatchers',
        'opt-in',
        'rollback-refreshers',
        'hooks',
        'admin-menu',
    ];

    /** Request URI each context's probe serves while the plugin loads. */
    private const REQUEST_URIS = [
        'frontend' => '/',
        'admin'    => '/wp-admin/index.php',
        'ajax'     => '/wp-admin/admin-ajax.php',
        'cron'     => '/wp-cron.php',
        'rest'     => '/wp-json/mcp/wpmcp-server',
        'cli'      => '',
    ];

    /** Lines of scripts/build-wporg-release.sh that stage() mirrors. */
    private const WPORG_RECIPE = [
        'cp "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"',
        'cp "$ROOT/uninstall.php" "$STAGE/"',
        'cp -R "$ROOT/src" "$STAGE/src"',
        'sed "s/{{VERSION}}/$VERSION/g" "$FLAVOR/$SLUG.php" > "$STAGE/$SLUG.php"',
        'sed "s/{{VERSION}}/$VERSION/g" "$FLAVOR/readme.txt" > "$STAGE/readme.txt"',
        'php "$FLAVOR/strip.php" "$STAGE"',
    ];

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var array<string, array{flavor: string, context: string, root: string, checkout: string}> */
    private static array $probe = [];

    /** @var array<string, mixed> what the probe records while the plugin loads */
    private static array $load = [];

    public static function baseline_dir(): string
    {
        return __DIR__ . '/baseline';
    }

    /** @return string[] the *.json files in the committed baseline, sorted. */
    public static function baseline_files(): array
    {
        $files = array_map('basename', glob(self::baseline_dir() . '/*.json') ?: []);
        sort($files);
        return $files;
    }

    /** @param array<string, string> $files */
    public static function write_baseline(array $files): void
    {
        if (! is_dir(self::baseline_dir())) {
            mkdir(self::baseline_dir(), 0777, true);
        }
        foreach (self::baseline_files() as $stale) {
            if (! isset($files[ $stale ])) {
                unlink(self::baseline_dir() . '/' . $stale);
            }
        }
        foreach ($files as $name => $json) {
            file_put_contents(self::baseline_dir() . '/' . $name, $json);
        }
    }

    // ------------------------------------------------------------------
    // Parent side
    // ------------------------------------------------------------------

    /**
     * Run every probe and return the baseline texts, file name => JSON.
     *
     * @return array<string, string>
     */
    public static function files(string $checkout): array
    {
        $checkout = rtrim($checkout, '/');
        $work     = self::temp_dir('wpmcp-golden-');
        try {
            $stage = self::stage_wporg($checkout, $work . '/stage');
            $out   = [];
            foreach (self::FLAVORS as $flavor) {
                $root    = 'wporg' === $flavor ? $stage : $checkout;
                $reports = [];
                foreach (self::CONTEXTS as $context) {
                    $reports[ $context ] = self::run_probe($checkout, $root, $flavor, $context, $work);
                }
                foreach (self::assemble($reports) as $concern => $data) {
                    $out[ "{$flavor}.{$concern}.json" ] = self::encode($data);
                }
            }
            ksort($out);
            return $out;
        } finally {
            self::remove_tree($work);
        }
    }

    /**
     * Build the directory (wporg) stage the way scripts/build-wporg-release.sh
     * does before its gates: copy the shipped files, render the flavor's main
     * file and readme, run strip.php. composer is not run; the stage gets a
     * vendor/autoload.php shim instead (see the probe), which points the
     * \WPMCP\ namespace at the stage and everything else at the checkout's
     * vendor directory.
     */
    public static function stage_wporg(string $checkout, string $stage): string
    {
        $script = (string) file_get_contents($checkout . '/scripts/build-wporg-release.sh');
        foreach (self::WPORG_RECIPE as $line) {
            if (false === strpos($script, $line)) {
                throw new \RuntimeException(
                    "scripts/build-wporg-release.sh no longer contains `{$line}`. "
                    . 'Update the staging steps in tests/support/golden/capture.php (stage_wporg) to match it.'
                );
            }
        }
        if (! preg_match("/define\\( 'WPMCP_VERSION', '([0-9.]+)' \\);/", (string) file_get_contents($checkout . '/wpmcp.php'), $m)) {
            throw new \RuntimeException('Could not read WPMCP_VERSION from wpmcp.php.');
        }
        $version = $m[1];
        $flavor  = $checkout . '/scripts/flavors/wporg';

        mkdir($stage . '/languages', 0777, true);
        foreach ([ 'LICENSE', 'composer.json', 'composer.lock', 'uninstall.php' ] as $file) {
            copy($checkout . '/' . $file, $stage . '/' . $file);
        }
        self::run([ 'cp', '-R', $checkout . '/src', $stage . '/src' ]);
        foreach (glob($checkout . '/languages/*') ?: [] as $file) {
            if (preg_match('/\.(po|mo|l10n\.php)$/', $file)) {
                copy($file, $stage . '/languages/' . basename($file));
            }
        }
        foreach ([ 'wpmcp.php', 'readme.txt' ] as $file) {
            file_put_contents($stage . '/' . $file, str_replace('{{VERSION}}', $version, (string) file_get_contents($flavor . '/' . $file)));
        }
        self::run([ PHP_BINARY, $flavor . '/strip.php', $stage ]);

        mkdir($stage . '/vendor', 0777, true);
        file_put_contents(
            $stage . '/vendor/autoload.php',
            "<?php\n// Golden capture shim: the probe registered the autoloader before WordPress loaded.\nreturn \$GLOBALS['wpmcp_golden_loader'];\n"
        );

        return $stage;
    }

    /** @return \stdClass the probe's report */
    private static function run_probe(string $checkout, string $root, string $flavor, string $context, string $work): \stdClass
    {
        $out = "{$work}/{$flavor}-{$context}.json";
        $env = getenv();
        $env['WP_TESTS_SKIP_INSTALL'] = '1';
        // A probe must not report into the parent's coverage or junit files.
        unset($env['XDEBUG_MODE']);

        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=1G',
            __DIR__ . '/probe.php',
            '--flavor=' . $flavor,
            '--context=' . $context,
            '--root=' . $root,
            '--checkout=' . $checkout,
            '--out=' . $out,
        ];
        [ $status, $output ] = self::run($cmd, $env, false);
        if (0 !== $status || ! is_file($out)) {
            throw new \RuntimeException(sprintf(
                "Golden probe %s/%s failed (exit %d):\n%s",
                $flavor,
                $context,
                $status,
                substr($output, -6000)
            ));
        }
        // Objects kept as objects: the wire tools carry {} where PHP has [].
        $report = json_decode((string) file_get_contents($out), false, 512, JSON_THROW_ON_ERROR);
        unlink($out);
        return $report;
    }

    /**
     * Turn one flavor's per-context reports into the per-concern files.
     *
     * @param array<string, \stdClass> $reports context => report
     * @return array<string, mixed> concern => data
     */
    private static function assemble(array $reports): array
    {
        $front = $reports['frontend'];
        $hooks = [];
        foreach (self::CONTEXTS as $context) {
            $hooks[ $context ] = $reports[ $context ]->hooks;
        }

        return [
            'abilities'           => $front->abilities,
            'tools'               => $front->tools,
            'held'                => $front->held,
            'dispatchers'         => $front->dispatchers,
            'opt-in'              => $front->opt_in,
            'rollback-refreshers' => $front->rollback_refreshers,
            'hooks'               => $hooks,
            'admin-menu'          => $reports['admin']->admin_menu,
        ];
    }

    /** Pretty JSON with two-space indents and a trailing newline. */
    public static function encode($data): string
    {
        $json = json_encode($data, self::JSON_FLAGS);
        $json = (string) preg_replace_callback(
            '/^( {4})+/m',
            static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)),
            $json
        );
        return $json . "\n";
    }

    /** A unified diff of one baseline file, capped so a failure stays readable. */
    public static function diff(string $file, string $expected, string $actual): string
    {
        $builder = new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder("--- baseline/{$file}\n+++ capture/{$file}\n");
        $diff    = (new \SebastianBergmann\Diff\Differ($builder))->diff($expected, $actual);
        $lines   = explode("\n", $diff);
        $max     = 300;
        if (count($lines) > $max) {
            $lines   = array_slice($lines, 0, $max);
            $lines[] = sprintf('... diff truncated at %d lines; regenerate into a scratch copy to see all of it.', $max);
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Probe side
    // ------------------------------------------------------------------

    /**
     * Probe entry point (tests/support/golden/probe.php). Sets the context
     * up, loads the test environment with the plugin of $root loaded at
     * muplugins_loaded, records, and writes the report to --out.
     *
     * @param array<string, string> $args
     */
    public static function probe(array $args): void
    {
        $flavor   = $args['flavor'] ?? '';
        $context  = $args['context'] ?? '';
        $root     = rtrim($args['root'] ?? '', '/');
        $checkout = rtrim($args['checkout'] ?? '', '/');
        if (! in_array($flavor, self::FLAVORS, true) || ! in_array($context, self::CONTEXTS, true) || ! is_dir($root) || ! is_dir($checkout)) {
            throw new \InvalidArgumentException('usage: probe.php --flavor=<f> --context=<c> --root=<dir> --checkout=<dir> --out=<file>');
        }
        $out         = (string) ($args['out'] ?? '');
        self::$probe = compact('flavor', 'context', 'root', 'checkout', 'out');

        if (! defined('WPMCP_TESTING')) {
            define('WPMCP_TESTING', true);
        }
        switch ($context) {
            case 'admin':
                define('WP_ADMIN', true);
                break;
            case 'ajax':
                define('WP_ADMIN', true);
                define('DOING_AJAX', true);
                break;
            case 'cron':
                define('DOING_CRON', true);
                break;
            case 'cli':
                require_once __DIR__ . '/wp-cli-stub.php';
                define('WP_CLI', true);
                break;
        }

        // tests/bootstrap.php require_once's this same file first thing, so
        // the loader it gets is this one. For the stage, \WPMCP\ resolves to
        // the stage's src/ and nothing of the checkout's src/ can load.
        $loader = require $checkout . '/vendor/autoload.php';
        if ($root !== $checkout) {
            $loader->setPsr4('WPMCP\\', [ $root . '/src/' ]);
        }
        $GLOBALS['wpmcp_golden_loader'] = $loader;

        // Hooked before WordPress exists, the way tests_add_filter() does:
        // load the plugin at muplugins_loaded priority 9, before the test
        // bootstrap's own loader at 10, which then finds WPMCP_VERSION
        // defined and defers.
        $GLOBALS['wp_filter']['muplugins_loaded'][9][] = [
            'function'      => [ self::class, 'load_plugin' ],
            'accepted_args' => 0,
        ];

        self::load_bootstrap($checkout . '/tests/bootstrap.php');
        ['flavor' => $flavor, 'context' => $context, 'out' => $out] = self::$probe;

        $report = [ 'hooks' => self::$load['hooks'] ];
        if ('frontend' === $context) {
            $report += self::surface();
        }
        if ('admin' === $context) {
            $report['admin_menu'] = self::admin_menu();
        }
        if ('cli' === $context) {
            $report['hooks']['wp_cli_commands'] = self::$load['wp_cli_commands'];
        }

        file_put_contents($out, json_encode(self::normalize($report), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * Include the test bootstrap the way PHPUnit does (FileLoader::load):
     * inside a function, then every variable it defined made global.
     */
    private static function load_bootstrap(string $wpmcp_golden_file): void
    {
        $wpmcp_golden_before = array_keys(get_defined_vars());
        include_once $wpmcp_golden_file;
        foreach (get_defined_vars() as $name => $value) {
            if (! in_array($name, $wpmcp_golden_before, true) && 'wpmcp_golden_before' !== $name) {
                $GLOBALS[ $name ] = $value;
            }
        }
    }

    /** muplugins_loaded:9 in a probe: load the plugin's main file between two hook snapshots. */
    public static function load_plugin(): void
    {
        ['flavor' => $flavor, 'context' => $context, 'root' => $root] = self::$probe;

        $_SERVER['REQUEST_URI'] = self::REQUEST_URIS[ $context ];
        if (class_exists('\\WPMCP\\Pro\\Gate')) {
            \WPMCP\Pro\Gate::set_pro_for_tests(true);
        }
        if ('woocommerce' === $flavor) {
            \WPMCP\Plugin::set_flavor_for_tests('woocommerce');
        }

        $stub     = 'cli' === $context;
        $commands = $stub ? count(\WP_CLI::$commands) : 0;
        $before   = self::hook_table();
        require $root . '/wpmcp.php';
        $after = self::hook_table();

        self::$load['hooks'] = self::hook_diff($before, $after);
        if ($stub) {
            self::$load['wp_cli_commands'] = array_map(
                static fn (array $c): array => [ 'name' => $c[0], 'callback' => self::callback_id($c[1]), 'args' => $c[2] ],
                array_slice(\WP_CLI::$commands, $commands)
            );
        }
    }

    /**
     * The report with this machine's paths taken out of every key and
     * string (hook names such as activate_<plugin file> carry them).
     */
    private static function normalize($value)
    {
        static $map = null;
        if (null === $map) {
            $paths = [
                self::$probe['root']     => '<plugin>',
                self::$probe['checkout'] => self::$probe['root'] === self::$probe['checkout'] ? '<plugin>' : '<checkout>',
                rtrim(ABSPATH, '/')      => '<abspath>',
            ];
            $map = [];
            foreach ($paths as $path => $token) {
                $map[ $path ]               = $token;
                $map[ ltrim($path, '/') ]   = $token;
            }
            uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }
        if (is_string($value)) {
            return strtr($value, $map);
        }
        if ($value instanceof \stdClass) {
            return (object) self::normalize(get_object_vars($value));
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[ is_string($k) ? strtr($k, $map) : $k ] = self::normalize($v);
            }
            return $out;
        }
        return $value;
    }

    // ---------------------------- hooks ---------------------------------

    /**
     * Every hooked callback: hook => list of [idx, priority, accepted_args, callable].
     *
     * @return array<string, array<int, array{0: string, 1: int, 2: int, 3: mixed}>>
     */
    private static function hook_table(): array
    {
        $table = [];
        foreach ((array) ($GLOBALS['wp_filter'] ?? []) as $hook => $wp_hook) {
            $callbacks = $wp_hook instanceof \WP_Hook ? $wp_hook->callbacks : (array) $wp_hook;
            ksort($callbacks);
            foreach ($callbacks as $priority => $entries) {
                foreach ((array) $entries as $idx => $entry) {
                    $table[ (string) $hook ][] = [ (string) $idx, (int) $priority, (int) ($entry['accepted_args'] ?? 1), $entry['function'] ?? null ];
                }
            }
        }
        return $table;
    }

    /**
     * What the load added and removed, hook => [[priority, callback, accepted_args], ...]
     * in execution order, hooks sorted by name.
     *
     * @return array{added: \stdClass, removed: \stdClass} hook => [[priority, callback, accepted_args], ...]
     */
    private static function hook_diff(array $before, array $after): array
    {
        $side = static function (array $from, array $minus): \stdClass {
            $out = [];
            foreach ($from as $hook => $entries) {
                $known = [];
                foreach ($minus[ $hook ] ?? [] as $entry) {
                    $known[ $entry[1] . '|' . $entry[0] ] = true;
                }
                foreach ($entries as $entry) {
                    if (! isset($known[ $entry[1] . '|' . $entry[0] ])) {
                        $out[ $hook ][] = [ $entry[1], self::callback_id($entry[3]), $entry[2] ];
                    }
                }
            }
            ksort($out, SORT_STRING);
            return (object) $out;
        };

        return [ 'added' => $side($after, $before), 'removed' => $side($before, $after) ];
    }

    /** A stable name for a callable: no object ids, paths relative to the plugin. */
    public static function callback_id($callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback) && 2 === count($callback)) {
            [ $target, $method ] = array_values($callback);
            return is_object($target) ? get_class($target) . '->' . $method : (string) $target . '::' . $method;
        }
        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            if (false === strpos($ref->getName(), '{closure')) {
                $scope = $ref->getClosureScopeClass();
                return 'fromCallable:' . (null !== $scope ? $scope->getName() . '::' : '') . $ref->getName();
            }
            return sprintf('closure@%s:%d', self::relative_path((string) $ref->getFileName()), (int) $ref->getStartLine());
        }
        if (is_object($callback)) {
            return get_class($callback) . '->__invoke';
        }
        return gettype($callback);
    }

    private static function relative_path(string $file): string
    {
        $roots = [
            self::$probe['root'] ?? ''     => '',
            self::$probe['checkout'] ?? '' => '',
        ];
        foreach ($roots as $root => $prefix) {
            if ('' !== $root && 0 === strpos($file, $root . '/')) {
                return $prefix . substr($file, strlen($root) + 1);
            }
        }
        if (defined('WP_PLUGIN_DIR') && 0 === strpos($file, WP_PLUGIN_DIR . '/')) {
            return 'plugins:' . substr($file, strlen(WP_PLUGIN_DIR) + 1);
        }
        if (defined('ABSPATH') && 0 === strpos($file, ABSPATH)) {
            return 'wp:' . substr($file, strlen(ABSPATH));
        }
        return 'other:' . basename($file);
    }

    // ---------------------------- surface --------------------------------

    /** @return array<string, mixed> abilities, tools, held, dispatchers, opt_in, rollback_refreshers */
    private static function surface(): array
    {
        $plugin = \WPMCP\Plugin::instance();
        $groups = self::group_map();

        // The registry fires wp_abilities_api_init on first use; let that
        // happen now, the natural way, so nothing re-enters it below.
        wp_get_abilities();

        // Natural pass: what this environment really registers, and what
        // it holds back because the host plugin is not loaded (issue #790).
        $natural = self::recording_registrar($groups);
        $plugin->register_abilities_into($natural);

        // Forced pass, the surface the ability manifest pins: every
        // integration registers (as RegisteredAbilities does), into a
        // fresh registrar swapped into the singleton, inside the
        // wp_abilities_api_init window so the abilities reach the Abilities
        // API and tools/list sees exactly them.
        add_filter('wpmcp_integration_should_register', '__return_true', PHP_INT_MAX - 1);
        foreach (array_keys(wp_get_abilities()) as $name) {
            if (0 === strpos((string) $name, 'wpmcp/')) {
                wp_unregister_ability((string) $name);
            }
        }
        $forced = self::recording_registrar($groups);
        $prop   = new \ReflectionProperty(\WPMCP\Plugin::class, 'registrar');
        $prop->setValue($plugin, $forced);
        $GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
        try {
            $plugin->register_abilities();
        } finally {
            array_pop($GLOBALS['wp_current_filter']);
        }

        $exposed = [];
        foreach ($forced->all() as $ability) {
            $exposed[ $ability->name ] = true;
        }
        $abilities = [];
        foreach ($forced->calls as $call) {
            $abilities[] = self::ability_record($call['ability'], $call['group'], $call['method'], isset($exposed[ $call['ability']->name ]));
        }

        return [
            'abilities'           => $abilities,
            'tools'               => self::wire_tools(),
            'held'                => self::held($natural),
            'dispatchers'         => self::dispatchers(array_merge(
                array_column($forced->calls, 'ability'),
                array_column($natural->held(), 'ability')
            )),
            'opt_in'              => self::opt_in(),
            'rollback_refreshers' => self::rollback_refreshers(),
        ];
    }

    /**
     * $groups of register_abilities_into(), method => group key, read from
     * the source of the Plugin class that loaded (checkout or stage).
     *
     * @return array<string, string>
     */
    private static function group_map(): array
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(\WPMCP\Plugin::class))->getFileName());
        preg_match_all("/'([a-z_]+)'\\s*=>\\s*fn \\(\\) => \\\$this->(register_[a-z_]+)\\(\\\$registrar\\)/", $source, $m, PREG_SET_ORDER);
        $map = [];
        foreach ($m as $row) {
            $map[ $row[2] ] = $row[1];
        }
        if ([] === $map) {
            throw new \RuntimeException('Could not read the $groups map of Plugin::register_abilities_into().');
        }
        return $map;
    }

    /**
     * A Registrar that remembers every register() call in order, with the
     * group it came from: the $groups key of the outermost register_*
     * method on the stack ('prologue' for what register_abilities_into()
     * registers before the groups), and the innermost register_*_abilities
     * method.
     *
     * @param array<string, string> $groups
     */
    private static function recording_registrar(array $groups): \WPMCP\MCP\Registrar
    {
        return new class ($groups) extends \WPMCP\MCP\Registrar {
            /** @var array<int, array{ability: \WPMCP\MCP\Ability, group: string, method: string}> */
            public array $calls = [];

            /** @param array<string, string> $groups */
            public function __construct(private array $groups)
            {
            }

            public function register(\WPMCP\MCP\Ability $a): void
            {
                $outer = null;
                $inner = null;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- frame names only.
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (\WPMCP\Plugin::class !== ($frame['class'] ?? '')) {
                        continue;
                    }
                    $fn = (string) $frame['function'];
                    if (in_array($fn, [ 'register_abilities_into', 'register_abilities' ], true)) {
                        break;
                    }
                    if (0 === strpos($fn, 'register_')) {
                        $outer = $fn;
                        if (null === $inner && preg_match('/^register_.*_abilities$/', $fn)) {
                            $inner = $fn;
                        }
                    }
                }
                $this->calls[] = [
                    'ability' => $a,
                    'group'   => null !== $outer && isset($this->groups[ $outer ]) ? $this->groups[ $outer ] : 'prologue',
                    'method'  => $inner ?? $outer ?? 'register_abilities_into',
                ];
                parent::register($a);
            }
        };
    }

    /** @return array<string, mixed> */
    private static function ability_record(\WPMCP\MCP\Ability $a, string $group, string $method, bool $exposed): array
    {
        return [
            'name'             => $a->name,
            'tier'             => $a->tier,
            'group'            => $group,
            'method'           => $method,
            'handler'          => self::callback_id($a->handler),
            'description'      => $a->description,
            'input_schema'     => $a->input_schema,
            'capability'       => $a->capability,
            'domain'           => $a->domain,
            'operation'        => $a->operation,
            'read_only_hint'   => $a->read_only_hint,
            'destructive_hint' => $a->destructive_hint,
            'idempotent_hint'  => $a->idempotent_hint,
            'read_keys'        => $a->read_keys,
            'has_objects'      => null !== $a->objects,
            'has_op_capability' => null !== $a->op_capability,
            'exposed'          => $exposed,
        ];
    }

    /**
     * tools/list as the server answers it: Stdio_Transport::list_tools(),
     * the list stdio and the HTTP 2026-07-28 path serve, after Wire_Schema,
     * Output_Schemas, Tool_Annotations and the exposure filter, in full
     * exposure mode.
     *
     * @return array<int, mixed>
     */
    private static function wire_tools(): array
    {
        $mode = static fn (): string => \WPMCP\MCP\Tool_Exposure::MODE_FULL;
        add_filter('pre_option_' . \WPMCP\MCP\Tool_Exposure::OPTION, $mode);
        try {
            $list  = new \ReflectionMethod(\WPMCP\MCP\Stdio_Transport::class, 'list_tools');
            $tools = $list->invoke(new \WPMCP\MCP\Stdio_Transport());
        } finally {
            remove_filter('pre_option_' . \WPMCP\MCP\Tool_Exposure::OPTION, $mode);
        }
        // Through JSON and back with objects kept, so {} and [] stay apart.
        return json_decode((string) wp_json_encode($tools), false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, array<string, mixed>> sorted by name */
    private static function held(\WPMCP\MCP\Registrar $natural): array
    {
        $entries = (new \ReflectionProperty(\WPMCP\MCP\Registrar::class, 'held_for_plugin'))->getValue($natural);
        $out     = [];
        foreach ($entries as $name => $entry) {
            $out[] = [
                'name'              => (string) $name,
                'tier'              => $entry['ability']->tier,
                'plugin'            => $entry['plugin'],
                'several'           => $entry['several'],
                'operations'        => self::sorted($entry['operations']),
                'unknown_operation' => null !== ($entry['unknown_operation'] ?? null),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Every Integration_Dispatcher behind an ability: its settings, its
     * list-operations answer and its raw operation definitions with closures
     * dropped. Sorted by integration slug.
     *
     * @param \WPMCP\MCP\Ability[] $abilities
     * @return array<int, array<string, mixed>>
     */
    private static function dispatchers(array $abilities): array
    {
        $seen = [];
        foreach ($abilities as $ability) {
            $target = is_array($ability->handler) ? ($ability->handler[0] ?? null) : null;
            if ($target instanceof \WPMCP\Integrations\Integration_Dispatcher) {
                $seen[ $target->integration() ] ??= $target;
            }
        }
        ksort($seen, SORT_STRING);

        $out = [];
        foreach ($seen as $slug => $dispatcher) {
            $operations = (new \ReflectionMethod($dispatcher, 'operations'))->invoke($dispatcher);
            $record     = [ 'integration' => (string) $slug, 'class' => get_class($dispatcher) ];
            // The wporg strip removes methods it has no use for (tier()
            // among them), so ask only what this tree's class still has.
            foreach (
                [
                    'tier', 'capability', 'pair_capability', 'domain', 'is_available',
                    'registers_only_when_available', 'host_plugin', 'operation_hosts',
                ] as $method
            ) {
                if (method_exists($dispatcher, $method)) {
                    $value             = $dispatcher->$method();
                    $record[ $method ] = 'operation_hosts' === $method ? self::sorted($value) : $value;
                }
            }
            $record['read_operations']  = $dispatcher->operation_names('read');
            $record['write_operations'] = $dispatcher->operation_names('write');
            $record['list_operations']  = $dispatcher->handle_read([ 'operation' => 'list-operations' ]);
            $record['definitions']      = self::definitions($operations, $record['list_operations']);
            $out[]                      = $record;
        }
        return $out;
    }

    /**
     * The raw op definitions with closures dropped, minus the description
     * and input_schema the list-operations catalog already shows for the
     * same op (kept for an op the catalog leaves out).
     *
     * @param array<string, array<string, mixed>> $operations
     * @param array<string, mixed>                $catalog
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(array $operations, array $catalog): array
    {
        $listed = [];
        foreach ((array) ($catalog['result']['operations'] ?? []) as $op) {
            $listed[ (string) $op['name'] ] = $op;
        }
        $out = [];
        foreach ($operations as $name => $def) {
            $def = self::without_closures($def);
            foreach ([ 'description', 'input_schema' ] as $key) {
                if (isset($listed[ $name ]) && array_key_exists($key, $def) && wp_json_encode($def[ $key ]) === wp_json_encode(self::without_closures($listed[ $name ][ $key ]))) {
                    $def[ $key ] = '<as listed>';
                }
            }
            $out[ (string) $name ] = $def;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function opt_in(): array
    {
        $settings = \WPMCP\Governance\Opt_In_Settings::class;
        return [
            'all_groups' => $settings::all_groups(),
            'labels'     => $settings::labels(),
            'shipped'    => array_keys($settings::groups()),
        ];
    }

    /** @return array<int, array<string, string>> in declaration order */
    private static function rollback_refreshers(): array
    {
        $const = new \ReflectionClassConstant(\WPMCP\Plugin::class, 'ROLLBACK_REFRESHERS');
        $out   = [];
        foreach ((array) $const->getValue() as $row) {
            $out[] = [
                'class'   => (string) $row[0],
                'method'  => (string) $row[1],
                'hook'    => (string) ($row[2] ?? 'wpmcp_rollback_options_restored'),
                'shipped' => class_exists((string) $row[0]),
            ];
        }
        return $out;
    }

    /**
     * The plugin's admin menu for an administrator: admin_menu fired the
     * way wp-admin/menu.php fires it, then every entry whose slug names
     * wpmcp, in menu order.
     *
     * @return array<string, mixed>
     */
    private static function admin_menu(): array
    {
        global $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $menu              = [];
        $submenu           = [];
        $_registered_pages = [];
        $admin_page_hooks  = [];
        $_parent_pages     = [];

        $admins = get_users([ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ]);
        wp_set_current_user((int) ($admins[0] ?? 1));
        do_action('admin_menu', '');

        $ours = static fn ($slug): bool => false !== strpos((string) $slug, 'wpmcp');
        $top  = [];
        ksort($menu);
        foreach ($menu as $position => $item) {
            if ($ours($item[2] ?? '')) {
                $top[] = [ 'position' => (string) $position, 'title' => (string) $item[0], 'capability' => (string) $item[1], 'slug' => (string) $item[2], 'page_title' => (string) ($item[3] ?? ''), 'icon' => (string) ($item[6] ?? '') ];
            }
        }
        $subs = [];
        foreach ($submenu as $parent => $items) {
            foreach ($items as $item) {
                if ($ours($parent) || $ours($item[2] ?? '')) {
                    $subs[ (string) $parent ][] = [ 'title' => (string) $item[0], 'capability' => (string) $item[1], 'slug' => (string) $item[2], 'page_title' => (string) ($item[3] ?? '') ];
                }
            }
        }
        ksort($subs, SORT_STRING);
        $pages = array_values(array_filter(array_keys((array) $_registered_pages), $ours));
        sort($pages);
        $parents = array_filter((array) $_parent_pages, $ours, ARRAY_FILTER_USE_KEY);
        ksort($parents, SORT_STRING);

        return [ 'menu' => $top, 'submenu' => $subs, 'registered_pages' => $pages, 'parent_pages' => $parents ];
    }

    // ---------------------------- helpers --------------------------------

    /** Closures replaced by a marker, recursively; key order kept. */
    private static function without_closures($value)
    {
        if ($value instanceof \Closure || (is_object($value) && is_callable($value))) {
            return '<closure>';
        }
        if ($value instanceof \stdClass) {
            return (object) self::without_closures(get_object_vars($value));
        }
        if (is_object($value)) {
            return '<object ' . get_class($value) . '>';
        }
        if (is_array($value)) {
            if (2 === count($value) && array_is_list($value) && is_object($value[0]) && is_string($value[1])) {
                return '<callable ' . self::callback_id($value) . '>';
            }
            foreach ($value as $k => $v) {
                $value[ $k ] = self::without_closures($v);
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $map */
    private static function sorted(array $map): array
    {
        ksort($map, SORT_STRING);
        return $map;
    }

    private static function temp_dir(string $prefix): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        return (string) realpath($dir);
    }

    private static function remove_tree(string $dir): void
    {
        if ('' !== $dir && is_dir($dir) && false !== strpos($dir, 'wpmcp-golden-')) {
            self::run([ 'rm', '-rf', $dir ]);
        }
    }

    /**
     * Run a command without a shell.
     *
     * @param string[]                  $cmd
     * @param array<string, string>|null $env
     * @return array{0: int, 1: string} exit status and combined output
     */
    private static function run(array $cmd, ?array $env = null, bool $throw = true): array
    {
        $proc = proc_open($cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('Could not start ' . $cmd[0]);
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($proc);
        if ($throw && 0 !== $status) {
            throw new \RuntimeException(sprintf("%s failed (exit %d):\n%s", implode(' ', $cmd), $status, $output));
        }
        return [ $status, $output ];
    }
}
