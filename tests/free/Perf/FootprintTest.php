<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Perf;

/**
 * What WPMCP loads per request context (module architecture, section 3.6
 * and acceptance criteria A1), gated on the counts that do not vary between
 * runs: WPMCP src files, Freemius SDK files, declared WPMCP\ classes and
 * WPMCP abilities registered. Times are not gated; bin/bench/run.php
 * reports them.
 *
 * Each context boots in a fresh PHP process (tests/support/probes/footprint.php)
 * on tables of its own, with WPMCP the only plugin, and runs twice; the
 * second, steady-state run is the one measured, so first-request seeding
 * does not count.
 *
 * MEASURED on origin/main bb5d778f (22 Nov 2026), WordPress 7.1, PHP 8.5:
 *
 *   context  src files (bytes)     Plugin.php  Freemius files  classes  abilities
 *   front    124 (2,193,760)       loaded      49              119      0
 *   admin    119 (2,179,265)       loaded      51              115      0
 *   rest     738 (8,275,124)       loaded      49              732      255
 *   mcp      743 (8,318,060)       loaded      49              737      255
 *
 * (255 abilities: the free tier with no third-party plugin active. With the
 * canonical test plugins active, bin/bench/run.php sees 266.)
 *
 * Since the first measurement (fd3e70c8, the same day: 105, 100, 627, 632
 * src files) the budgets were raised, not the code changed, for two causes
 * the module migration is the fix for. The kernel itself (Kernel_Boot,
 * Registry, Container, Hook_Loader, Presence, Request_Context,
 * Route_Scoped_Mount and two Api\V1 contracts) loads in every context: 10
 * of the 19 new front-end files. The rest are packs that still register
 * through Plugin.php: each one adds its hooks to every request and, while
 * WPMCP_ROUTE_SCOPED_MCP is off (the default), its operations and store
 * classes to every REST request, because the adapter registers every
 * ability on rest_api_init. No single merge regressed: every pack merged
 * that day landed on the legacy path.
 *
 * 2.0.1 (25 Nov 2026) lowered the front and admin budgets: 2.0.0 loaded
 * 131 and 126 src files there, 2.0.1 loads 103 and 98 (102 and 98 classes).
 * The rollback refreshers are hooked after a check that does not load their
 * classes (Kernel_Boot::ships()), the #[On_Rollback] rows of modules attach
 * on the first rollback instead of on every request, and the stdio transport
 * and the route-scoped mount load only where they apply. The same margin
 * rule gives 115 and 110.
 *
 * BUDGETS are those numbers plus a margin of 10 percent, at least 5, rounded
 * up to a multiple of 5, so that feature PRs merging alongside the module
 * work do not turn main red. Abilities on the front end and in wp-admin are
 * gated at their measured value exactly (0 today). The module migration
 * lowers each budget as it lands; the A1 targets are 25 src files on the
 * front end with no Plugin.php, 30 and no abilities on an unrelated REST
 * call, 45 for tools/list.
 */
final class FootprintTest extends \WP_UnitTestCase
{
    /** context => metric => budget (at most). */
    private const BUDGETS = [
        'front' => ['src' => 115, 'freemius' => 55, 'classes' => 115, 'abilities' => 0],
        'admin' => ['src' => 110, 'freemius' => 60, 'classes' => 110, 'abilities' => 0],
        'rest'  => ['src' => 815, 'freemius' => 55, 'classes' => 810, 'abilities' => 285],
        'mcp'   => ['src' => 820, 'freemius' => 55, 'classes' => 815, 'abilities' => 285],
    ];

    private static string $prefix = '';

    /** @var array<string, array<string, mixed>> */
    private static array $measured = [];

    public static function tear_down_after_class(): void
    {
        if ('' !== self::$prefix) {
            self::probe('drop');
            self::$prefix = '';
        }
        parent::tear_down_after_class();
    }

    /** @dataProvider contexts */
    public function test_the_context_stays_within_its_budget(string $context): void
    {
        $record = $this->measure($context);
        $files  = (array) $record['files'];
        $got    = [
            'src'       => (int) $files['src'],
            'freemius'  => (int) $files['freemius'],
            'classes'   => (int) $record['classes'],
            'abilities' => (int) $record['abilities']['wpmcp'],
        ];
        $summary = sprintf(
            '%s: %d src files (%d bytes, Plugin.php %s), %d Freemius files, %d WPMCP classes, %d WPMCP abilities registered',
            $context,
            $got['src'],
            (int) $files['src_bytes'],
            $files['plugin_php'] ? 'loaded' : 'not loaded',
            $got['freemius'],
            $got['classes'],
            $got['abilities']
        );
        fwrite(STDERR, "\n[footprint] " . $summary . "\n");

        foreach (self::BUDGETS[ $context ] as $metric => $budget) {
            $this->assertLessThanOrEqual(
                $budget,
                $got[ $metric ],
                sprintf('%s over budget on %s (%d > %d). %s. Lower what loads, or raise the budget in this test with the reason.', $context, $metric, $got[ $metric ], $budget, $summary)
            );
        }
    }

    public function test_the_mcp_context_really_listed_the_tools(): void
    {
        $record = $this->measure('mcp');
        $this->assertSame(200, $record['tools_list']['status'] ?? null, 'The mcp context must answer tools/list, or its counts measure nothing');
        $this->assertGreaterThan(0, $record['tools_list']['tools'] ?? 0, 'The mcp context must list tools');
    }

    /** @return array<string, array{0: string}> */
    public static function contexts(): array
    {
        return ['front' => ['front'], 'admin' => ['admin'], 'rest' => ['rest'], 'mcp' => ['mcp']];
    }

    /** @return array<string, mixed> */
    private function measure(string $context): array
    {
        if (is_multisite()) {
            $this->markTestSkipped('The footprint is measured on a single site.');
        }
        if (null === self::config()) {
            $this->markTestSkipped('No wp-tests-config.php to boot a fresh WordPress from.');
        }
        if (! isset(self::$measured[ $context ])) {
            if ('' === self::$prefix) {
                self::$prefix = 'wpfp' . getmypid() . '_';
                self::probe('drop');
                $this->assertArrayHasKey('user_id', self::probe('install'), 'wp_install() into the footprint tables');
                self::probe('activate');
            }
            self::probe($context);
            self::$measured[ $context ] = self::probe($context);
        }
        $record = self::$measured[ $context ];
        $this->assertArrayHasKey('files', $record, 'The probe must print its record');

        return $record;
    }

    private static function config(): ?string
    {
        $config = getenv('WP_TESTS_CONFIG_FILE_PATH');
        if (! $config) {
            $config = rtrim((string) getenv('WP_TESTS_DIR'), '/') . '/wp-tests-config.php';
        }
        return is_readable($config) ? $config : null;
    }

    /** @return array<string, mixed> */
    private static function probe(string $mode): array
    {
        $root   = dirname(__DIR__, 3);
        $cmd    = sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tests/support/probes/footprint.php'),
            escapeshellarg((string) self::config()),
            escapeshellarg($root),
            escapeshellarg($mode),
            escapeshellarg(self::$prefix)
        );
        $output = (string) shell_exec($cmd);
        $at     = strrpos($output, 'FOOTPRINT ');
        $json   = false === $at ? null : json_decode(trim(strtok(substr($output, $at + 10), "\n")), true);
        if (! is_array($json)) {
            throw new \RuntimeException(sprintf("footprint probe %s printed no record:\n%s", $mode, substr($output, -3000)));
        }
        if (preg_match('/FOOTPRINT_MCP (\d+) (-?\d+)/', $output, $m)) {
            $json['tools_list'] = ['status' => (int) $m[1], 'tools' => (int) $m[2]];
        }
        return $json;
    }
}
