<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * Whether a checkout's build/registry is missing or older than what it is
 * compiled from, and the rebuild when it is (dev only, never shipped).
 *
 * composer's post-autoload-dump writes build/registry, so a worktree whose
 * vendor/ was copied instead of installed has none, and the plugin then boots
 * with no modules: their integrations and opt-in switches disappear and the
 * suite fails far from the cause. The test bootstraps (tests/bootstrap.php,
 * tests/bootstrap-fast.php) and bin/test-local.sh call ensure() first.
 *
 * The rebuild writes build/registry only (no artifacts, no surface.lock.php),
 * so it never edits a committed file; `composer registry:check` still fails a
 * stale lock. A copy without bin/build-registry.php (a release zip) is never
 * stale: it ships its registry.
 */
final class Freshness
{
    /** What the registry is compiled from, relative to the checkout root. */
    public const INPUTS = ['src/Modules', 'pro/modules', 'src/Api', 'tools/registry/src', 'bin/build-registry.php'];

    public const OUT = 'build/registry';

    /**
     * True when build/registry is missing or older than an input. It reads the
     * filesystem, so a second call can answer differently.
     *
     * @phpstan-impure
     */
    public static function stale(string $root): bool
    {
        if (! is_file($root . '/bin/build-registry.php')) {
            return false;
        }
        $boot = $root . '/' . self::OUT . '/boot.php';
        if (! is_file($boot)) {
            return true;
        }
        return self::newest_input($root) > (int) filemtime($boot);
    }

    /**
     * Rebuilds build/registry when it is stale, one process at a time (the
     * parallel gate starts many bootstraps at once).
     *
     * @return string|null null when the registry is fresh, else why it could not be built
     */
    public static function ensure(string $root): ?string
    {
        if (! self::stale($root)) {
            return null;
        }
        if (! is_dir($root . '/build') && ! mkdir($root . '/build', 0777, true) && ! is_dir($root . '/build')) {
            return 'cannot create ' . $root . '/build';
        }
        $lock = fopen($root . '/build/.registry-freshness.lock', 'c');
        if (false === $lock) {
            return 'cannot open the registry lock in ' . $root . '/build';
        }
        try {
            flock($lock, LOCK_EX);
            if (! self::stale($root)) {
                return null;
            }
            $command = sprintf(
                '%s %s --quiet --artifacts= --no-locks 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root . '/bin/build-registry.php')
            );
            $output = [];
            $status = 0;
            exec($command, $output, $status);
            if (0 !== $status) {
                return "bin/build-registry.php failed:\n" . implode("\n", $output);
            }
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function newest_input(string $root): int
    {
        $newest = 0;
        foreach (self::INPUTS as $input) {
            $path = $root . '/' . $input;
            if (is_file($path)) {
                $newest = max($newest, (int) filemtime($path));
                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $newest = max($newest, $file->getMTime());
                }
            }
        }
        return $newest;
    }
}
