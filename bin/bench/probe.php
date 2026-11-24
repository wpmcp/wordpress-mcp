<?php

/**
 * Plugin Name: WPMCP bench probe
 * Description: Records what WPMCP loads on each request of the benchmark environment (bin/bench/env.sh).
 *
 * REPO-ONLY, like the rest of bin/: the release zips never ship it. It is a
 * must-use plugin that bin/bench/env.sh copies into a throwaway site, and it
 * does nothing unless that site's wp-config.php defines WPMCP_BENCH as true.
 *
 * On every request it records, against the start of the request
 * (REQUEST_TIME_FLOAT), the time and memory at muplugins_loaded,
 * plugins_loaded (PHP_INT_MAX), wp_loaded (PHP_INT_MAX) and shutdown
 * (PHP_INT_MAX), with hrtime() for the phase durations. At shutdown it adds:
 *
 *  - the files PHP included under WPMCP_DIR, split into src/Plugin.php, the
 *    rest of src/, vendor/freemius and the rest of vendor/, with their bytes;
 *  - the WPMCP\ classes declared;
 *  - how many abilities were registered (the wp_register_ability_args filter
 *    runs once per registration), WPMCP's and every plugin's, and how long
 *    wp_abilities_api_init took, measured here and as Handshake_Timing (the
 *    Server-Timing `abilities` value) saw it;
 *  - the PHP version and whether opcache served the request.
 *
 * The record is appended as one JSON line to WPMCP_BENCH_LOG (default
 * wp-content/wpmcp-bench.jsonl) and sent as an X-WPMCP-Bench header while
 * headers can still be sent. A REST response has sent its headers before
 * shutdown, so on the REST path the header carries the record as it stood at
 * rest_post_dispatch instead (phase "rest"); the log line is always the
 * complete one. bin/bench/run.php tags each request with an
 * X-WPMCP-Bench-Id header and reads its line back from the log.
 *
 * Two request headers steer a request, only on a bench site:
 *
 *  - X-WPMCP-Bench-Inactive: 1 drops WPMCP (WPMCP_BENCH_PLUGIN, default
 *    wpmcp/wpmcp.php) from active_plugins for that request, so the same
 *    server measures the cost of the same page without WPMCP (the "delta"
 *    of the acceptance criteria: active minus inactive);
 *  - X-WPMCP-Bench-Exposure: compact|full forces the tool exposure mode
 *    through the wpmcp_tool_exposure_mode filter (scenario S8).
 *
 * tests/support/probes/footprint.php reuses Probe::snapshot() for the
 * deterministic file and class counts of tests/free/Perf/FootprintTest.php.
 */

declare(strict_types=1);

namespace WPMCP\Bench;

if (! defined('ABSPATH')) {
    exit;
}

final class Probe
{
    /** @var array<string, array{ms: float, hr: int, mem: int}> */
    private static array $marks = [];

    private static int $abilities_all = 0;

    private static int $abilities_wpmcp = 0;

    private static ?int $abilities_started = null;

    private static ?float $abilities_ms = null;

    private static bool $written = false;

    public static function enabled(): bool
    {
        return defined('WPMCP_BENCH') && true === constant('WPMCP_BENCH');
    }

    public static function install(): void
    {
        if (! self::enabled() || ! function_exists('add_action')) {
            return;
        }

        self::mark('muplugins_loaded');

        if ('1' === self::request_header('X-WPMCP-Bench-Inactive')) {
            add_filter('option_active_plugins', [self::class, 'without_wpmcp'], PHP_INT_MAX);
        }
        $exposure = self::request_header('X-WPMCP-Bench-Exposure');
        if ('compact' === $exposure || 'full' === $exposure) {
            add_filter('wpmcp_tool_exposure_mode', static fn () => $exposure, PHP_INT_MAX);
        }

        self::count_abilities();
        add_action('plugins_loaded', static function (): void {
            self::mark('plugins_loaded');
        }, PHP_INT_MAX);
        add_action('wp_loaded', static function (): void {
            self::mark('wp_loaded');
        }, PHP_INT_MAX);
        add_filter('rest_post_dispatch', [self::class, 'rest_header'], PHP_INT_MAX);
        add_action('shutdown', [self::class, 'shutdown'], PHP_INT_MAX);
    }

    /** Counts registrations and times wp_abilities_api_init; safe to call without the bench switch. */
    public static function count_abilities(): void
    {
        add_filter('wp_register_ability_args', static function ($args, $name = '') {
            self::$abilities_all++;
            if (is_string($name) && str_starts_with($name, 'wpmcp/')) {
                self::$abilities_wpmcp++;
            }
            return $args;
        }, PHP_INT_MAX, 2);
        add_action('wp_abilities_api_init', static function (): void {
            self::$abilities_started = hrtime(true);
        }, PHP_INT_MIN);
        add_action('wp_abilities_api_init', static function (): void {
            if (null !== self::$abilities_started) {
                self::$abilities_ms      = (hrtime(true) - self::$abilities_started) / 1e6;
                self::$abilities_started = null;
            }
        }, PHP_INT_MAX);
    }

    public static function mark(string $name): void
    {
        $start                = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
        self::$marks[ $name ] = [
            'ms'  => round((microtime(true) - $start) * 1000, 2),
            'hr'  => hrtime(true),
            'mem' => memory_get_usage(),
        ];
    }

    /**
     * @param array<int, string> $plugins
     * @return array<int, string>
     */
    public static function without_wpmcp($plugins): array
    {
        $main = defined('WPMCP_BENCH_PLUGIN') ? (string) constant('WPMCP_BENCH_PLUGIN') : 'wpmcp/wpmcp.php';

        return array_values(array_filter((array) $plugins, static fn ($p) => $p !== $main));
    }

    /**
     * @param mixed $response
     * @return mixed
     */
    public static function rest_header($response)
    {
        if ($response instanceof \WP_HTTP_Response) {
            $response->header('X-WPMCP-Bench', (string) json_encode(self::snapshot('rest')));
        }
        return $response;
    }

    public static function shutdown(): void
    {
        if (self::$written) {
            return;
        }
        self::$written = true;
        self::mark('shutdown');
        $record = self::snapshot('shutdown');
        if (! headers_sent()) {
            header('X-WPMCP-Bench: ' . json_encode($record));
        }
        $log = defined('WPMCP_BENCH_LOG') ? (string) constant('WPMCP_BENCH_LOG') : WP_CONTENT_DIR . '/wpmcp-bench.jsonl';
        file_put_contents($log, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The record of this request so far.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(string $phase): array
    {
        $dir   = defined('WPMCP_DIR') ? rtrim((string) realpath((string) constant('WPMCP_DIR')), '/') . '/' : '';
        $files = self::files($dir);

        $classes = 0;
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'WPMCP\\') && ! str_starts_with($class, 'WPMCP\\Bench\\')) {
                $classes++;
            }
        }

        $timing = [];
        $memory = [];
        $first  = self::$marks['muplugins_loaded']['hr'] ?? null;
        foreach (self::$marks as $name => $mark) {
            $timing[ $name ] = $mark['ms'];
            $memory[ $name ] = $mark['mem'];
        }
        $phases = [];
        if (null !== $first) {
            $previous = $first;
            foreach (self::$marks as $name => $mark) {
                if ('muplugins_loaded' !== $name) {
                    $phases[ $name ] = round(($mark['hr'] - $previous) / 1e6, 2);
                    $previous        = $mark['hr'];
                }
            }
        }

        $opcache = false;
        if (function_exists('opcache_get_status')) {
            $status  = opcache_get_status(false);
            $opcache = is_array($status) && ! empty($status['opcache_enabled']);
        }

        return [
            'id'           => self::request_header('X-WPMCP-Bench-Id'),
            'phase'        => $phase,
            'method'       => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'CLI',
            'uri'          => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
            'wpmcp_active' => defined('WPMCP_VERSION'),
            'php'          => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'opcache'      => $opcache,
            'ms'           => $timing,
            'phase_ms'     => $phases,
            'mem'          => $memory,
            'peak_mem'     => memory_get_peak_usage(),
            'files'        => $files,
            'classes'      => $classes,
            'abilities'    => [
                'init_fired'   => function_exists('did_action') ? did_action('wp_abilities_api_init') > 0 : false,
                'registered'   => self::$abilities_all,
                'wpmcp'        => self::$abilities_wpmcp,
                'ms'           => null === self::$abilities_ms ? null : round(self::$abilities_ms, 2),
                'handshake_ms' => self::handshake_abilities_ms(),
            ],
        ];
    }

    /**
     * Included files, total and under the WPMCP directory, split by area.
     *
     * @return array<string, int>
     */
    public static function files(string $dir): array
    {
        $out = [
            'all'                => 0,
            'wpmcp'              => 0,
            'wpmcp_bytes'        => 0,
            'plugin_php'         => 0,
            'plugin_php_bytes'   => 0,
            'src'                => 0,
            'src_bytes'          => 0,
            'freemius'           => 0,
            'freemius_bytes'     => 0,
            'vendor_other'       => 0,
            'vendor_other_bytes' => 0,
        ];
        foreach (get_included_files() as $file) {
            $out['all']++;
            if ('' === $dir || ! str_starts_with($file, $dir)) {
                continue;
            }
            $bytes = (int) filesize($file);
            $rel   = substr($file, strlen($dir));
            $out['wpmcp']++;
            $out['wpmcp_bytes'] += $bytes;
            if (str_starts_with($rel, 'src/')) {
                $out['src']++;
                $out['src_bytes'] += $bytes;
                if ('src/Plugin.php' === $rel) {
                    $out['plugin_php']++;
                    $out['plugin_php_bytes'] += $bytes;
                }
            } elseif (str_starts_with($rel, 'vendor/freemius/')) {
                $out['freemius']++;
                $out['freemius_bytes'] += $bytes;
            } elseif (str_starts_with($rel, 'vendor/')) {
                $out['vendor_other']++;
                $out['vendor_other_bytes'] += $bytes;
            }
        }
        return $out;
    }

    /** What Handshake_Timing measured for wp_abilities_api_init, when it ran. */
    private static function handshake_abilities_ms(): ?float
    {
        $class = 'WPMCP\\MCP\\Handshake_Timing';
        if (! class_exists($class, false) || ! property_exists($class, 'abilities_ms')) {
            return null;
        }
        $value = (new \ReflectionProperty($class, 'abilities_ms'))->getValue();

        return is_float($value) ? round($value, 2) : null;
    }

    private static function request_header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[ $key ]) ? substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $_SERVER[ $key ]) ?? '', 0, 120) : '';
    }
}

Probe::install();
