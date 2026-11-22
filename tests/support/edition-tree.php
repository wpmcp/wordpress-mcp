<?php

declare(strict_types=1);

namespace WPMCP\Tests\Support;

/**
 * Which edition this checkout carries, for tests that run in every tree:
 *
 *  - unsplit: the Pro code still sits at its public paths under src/;
 *  - split with pro/: Pro lives in pro/ (the private submodule);
 *  - split without pro/: the free tree, what the free zip ships.
 *
 * Guard tests use public_sources() and pro_sources() instead of walking
 * src/ themselves, so the public half of a guard sees the same files before
 * and after the split, and the Pro half (under tests/pro, moved to
 * pro/tests by the split) sees the Pro files wherever they are. Paths are
 * reported as their public src/... path in both trees, which keeps
 * allowlists keyed the same way.
 */
final class Edition_Tree
{
    /** @var list<string>|null */
    private static ?array $manifest = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** True when pro/ is checked out. */
    public static function has_pro_dir(): bool
    {
        return is_file(self::root() . '/pro/src/Pro/bootstrap.php');
    }

    /** True when the Pro code is present at all: pro/ checked out, or the tree is not split yet. */
    public static function has_pro_code(): bool
    {
        if (self::has_pro_dir()) {
            return true;
        }
        foreach (self::manifest() as $path) {
            if (str_ends_with($path, '.php') && is_file(self::root() . '/' . $path)) {
                return true;
            }
        }
        return false;
    }

    /** True once bin/pro-split.php has run on this tree. */
    public static function is_split(): bool
    {
        return str_contains((string) file_get_contents(self::root() . '/src/Plugin.php'), 'edition()->run(');
    }

    /**
     * The Pro-only paths of pro-manifest.txt (public src/... paths; a
     * directory ends in /).
     *
     * @return list<string>
     */
    public static function manifest(): array
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }
        $file = self::root() . '/pro-manifest.txt';
        $paths = [];
        foreach (is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $line = trim((string) $line);
            if ('' !== $line && '#' !== $line[0] && '!' !== $line[0]) {
                $paths[] = $line;
            }
        }
        // The slot class the split writes from Plugin.php.
        $paths[] = 'src/Pro/Plugin_Slots.php';
        return self::$manifest = $paths;
    }

    /** Whether a public src/... path (or the code that lived there) is Pro. */
    public static function is_pro_path(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'pro/')) {
            return true;
        }
        foreach (self::manifest() as $pattern) {
            if ($path === $pattern || (str_ends_with($pattern, '/') && str_starts_with($path, $pattern))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Public source files: src/ minus the Pro paths, keyed by src/... path.
     *
     * @return array<string, string> src/... path => absolute path
     */
    public static function public_sources(string $extension = 'php'): array
    {
        $out = [];
        foreach (self::walk(self::root() . '/src', $extension) as $absolute) {
            $relative = 'src/' . substr($absolute, strlen(self::root() . '/src/'));
            if (! self::is_pro_path($relative)) {
                $out[ $relative ] = $absolute;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Pro source files wherever they are (src/ before the split, pro/src/
     * after), keyed by their public src/... path. Empty in the free tree.
     *
     * @return array<string, string> src/... path => absolute path
     */
    public static function pro_sources(string $extension = 'php'): array
    {
        $out = [];
        foreach (self::walk(self::root() . '/src', $extension) as $absolute) {
            $relative = 'src/' . substr($absolute, strlen(self::root() . '/src/'));
            if (self::is_pro_path($relative)) {
                $out[ $relative ] = $absolute;
            }
        }
        foreach (self::walk(self::root() . '/pro/src', $extension) as $absolute) {
            $out[ 'src/' . substr($absolute, strlen(self::root() . '/pro/src/')) ] = $absolute;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, string> both halves */
    public static function all_sources(string $extension = 'php'): array
    {
        $out = self::public_sources($extension) + self::pro_sources($extension);
        ksort($out);
        return $out;
    }

    /**
     * The registered-ability manifest this tree must match: the whole
     * surface when the Pro code is present, the free part otherwise.
     *
     * @return array{total:int,free:int,pro:int,abilities:array<string,string>}
     */
    public static function ability_manifest(): array
    {
        /** @var array{total:int,free:int,pro:int,abilities:array<string,string>} $full */
        $full = require __DIR__ . '/ability-manifest.php';
        if (self::has_pro_code()) {
            return $full;
        }
        $free = array_filter($full['abilities'], static fn (string $tier): bool => 'free' === $tier);
        return ['total' => count($free), 'free' => count($free), 'pro' => 0, 'abilities' => $free];
    }

    /** Skip unless the Pro code is present. */
    public static function require_pro(\PHPUnit\Framework\TestCase $test): void
    {
        if (! self::has_pro_code()) {
            $test->markTestSkipped('needs the Pro code (pro/ is not checked out)');
        }
    }

    /** @return list<string> */
    private static function walk(string $dir, string $extension): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('' === $extension || $file->getExtension() === $extension) {
                $out[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($out);
        return $out;
    }
}
