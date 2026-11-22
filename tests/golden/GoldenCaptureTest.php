<?php

declare(strict_types=1);

namespace WPMCP\Tests\Golden;

use WPMCP\Tests\Support\Golden\Capture;

require_once __DIR__ . '/../support/golden/capture.php';

/**
 * The golden contract of the module migration (handoff/2026-11-22,
 * module-architecture.md sections 2.4 and 6, phase 0).
 *
 * Recomputes the legacy surface with tests/support/golden/capture.php and
 * compares it, file by file, with the committed baseline in
 * tests/support/golden/baseline/: every ability in registration order, the
 * wire tools of tools/list, held_for_plugin, every dispatcher pair's
 * list-operations catalog, the opt-in groups, ROLLBACK_REFRESHERS, the hooks
 * the plugin's main file adds per request context and the admin menu, for
 * the full, woocommerce and wporg flavors.
 *
 * Not part of any default run on purpose. It lives outside tests/free and
 * tests/pro, so phpunit.xml.dist, the CI shards (.github/ci/shard.php), the
 * parallel layout and the impact selection never pick it up: every feature
 * merge changes the ability surface, and a frozen baseline in the default
 * suite would turn main red on each one. Migration PRs run it explicitly:
 *
 *   bash bin/test-local.sh -- --group golden tests/golden
 *
 * and regenerate the baseline (then review and commit the diff) with:
 *
 *   WPMCP_GOLDEN_REGENERATE=1 bash bin/test-local.sh -- --group golden tests/golden
 *
 * @group golden
 */
final class GoldenCaptureTest extends \WP_UnitTestCase
{
    /** @var array<string, string>|null file name => JSON text, computed once per run */
    private static ?array $capture = null;

    /** A capture that failed, so the other tests report it without running it again. */
    private static ?\Throwable $failure = null;

    /** @return array<string, string> */
    private static function capture(): array
    {
        if (null !== self::$failure) {
            throw self::$failure;
        }
        if (null === self::$capture) {
            try {
                self::$capture = Capture::files(dirname(__DIR__, 2));
            } catch (\Throwable $e) {
                self::$failure = $e;
                throw $e;
            }
            if ('1' === getenv('WPMCP_GOLDEN_REGENERATE')) {
                Capture::write_baseline(self::$capture);
            }
        }
        return self::$capture;
    }

    public function test_the_capture_covers_every_flavor_and_concern(): void
    {
        $files = array_keys(self::capture());
        foreach (Capture::FLAVORS as $flavor) {
            foreach (Capture::CONCERNS as $concern) {
                $this->assertContains("{$flavor}.{$concern}.json", $files);
            }
        }
    }

    public function test_the_baseline_holds_no_file_the_capture_does_not_produce(): void
    {
        $stale = array_diff(Capture::baseline_files(), array_keys(self::capture()));
        $this->assertSame([], array_values($stale), 'Baseline files no capture produces; regenerate with WPMCP_GOLDEN_REGENERATE=1.');
    }

    public function test_the_capture_matches_the_baseline(): void
    {
        $failures = [];
        foreach (self::capture() as $file => $actual) {
            $path = Capture::baseline_dir() . '/' . $file;
            if (! is_file($path)) {
                $failures[] = "{$file}: no baseline file. Regenerate with WPMCP_GOLDEN_REGENERATE=1 and commit it.";
                continue;
            }
            $expected = (string) file_get_contents($path);
            if ($expected !== $actual) {
                $failures[] = Capture::diff($file, $expected, $actual);
            }
        }

        // fail() rather than assertSame(), so the report is the unified
        // diff alone and not a second rendering of it.
        if ([] !== $failures) {
            $this->fail(
                "The legacy surface drifted from tests/support/golden/baseline.\n\n" . implode("\n\n", $failures)
                . "\n\nIf the change is intended, regenerate with WPMCP_GOLDEN_REGENERATE=1 and commit the diff."
            );
        }
        $this->addToAssertionCount(count(self::capture()));
    }
}
