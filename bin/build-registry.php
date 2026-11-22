<?php

/**
 * The registry generator (dev only, never shipped): scans module roots for
 * WPMCP\Api\V1\Attribute classes and writes build/registry/ (gitignored
 * aggregates), build/artifacts/ (repo-only artifacts) and one committed
 * surface.lock.php per module. See tools/registry/src/Builder.php.
 *
 *   php bin/build-registry.php [--root=src] [--root=pro/modules] [--flavor=full]
 *       [--out=build/registry] [--artifacts=build/artifacts] [--exclude=path]
 *       [--check] [--all-flavors] [--auto] [--addon] [--quiet] [--no-locks]
 *
 *   composer registry:build   build the full flavor
 *   composer registry:check   validate every flavor and fail on a stale lock; writes nothing
 *
 * --artifacts= (empty) writes no artifacts and --no-locks no surface.lock.php,
 * so a release build can write build/registry into its stage and leave the
 * checkout untouched (scripts/lib/stage-registry.sh).
 *
 * --auto exits 0 without writing when no module exists yet (the composer
 * post-autoload-dump hook). Exit codes: 0 ok, 1 build failed, 2 bad usage.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
// The autoloader first, before ABSPATH exists: composer's "files" entries
// (the licensing SDK's start.php) return early without it instead of
// booting against the stubs.
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
require_once $root . '/tools/registry/wp-stubs.php';
// Works without the dev autoloader too (composer install --no-dev).
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['WPMCP\\Registry\\' => '/tools/registry/src/', 'WPMCP\\' => '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (1 !== preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unknown argument {$arg}\n");
        exit(2);
    }
    $args[ $m[1] ][] = $m[2] ?? true;
}
$known = ['root', 'flavor', 'out', 'artifacts', 'exclude', 'check', 'all-flavors', 'auto', 'addon', 'quiet', 'no-locks'];
foreach (array_keys($args) as $key) {
    if (! in_array($key, $known, true)) {
        fwrite(STDERR, "Unknown option --{$key}\n");
        exit(2);
    }
}
$abs = static fn (string $p): string => str_starts_with($p, '/') ? $p : $root . '/' . $p;
$one = static fn (string $key, string $default): string => is_string($args[ $key ][0] ?? null) ? $args[ $key ][0] : $default;

$roots = array_map($abs, array_map('strval', $args['root'] ?? ['src']));
$exclude = array_map($abs, array_map('strval', $args['exclude'] ?? ['src/Api', 'src/Kernel']));
$check = isset($args['check']);
$quiet = isset($args['quiet']);
$flavors = isset($args['all-flavors']) ? array_keys(\WPMCP\Registry\Flavors::all()) : [$one('flavor', 'full')];

$failed = false;
foreach ($flavors as $flavor) {
    $out = $abs($one('out', 'build/registry'));
    $options = new \WPMCP\Registry\Build_Options(
        roots: $roots,
        out: count($flavors) > 1 ? $out . '/' . $flavor : $out,
        flavor: $flavor,
        artifacts: '' === $one('artifacts', 'build/artifacts') ? null : $abs($one('artifacts', 'build/artifacts')),
        repo_root: $root,
        check: $check,
        auto: isset($args['auto']),
        addon: isset($args['addon']),
        exclude: $exclude,
        locks: ! isset($args['no-locks']),
    );
    $result = (new \WPMCP\Registry\Builder($options))->run();
    if ($result->skipped) {
        $quiet || fwrite(STDOUT, "registry: no modules yet, nothing to build\n");
        exit(0);
    }
    foreach ($result->errors as $error) {
        fwrite(STDERR, "registry [{$flavor}]: {$error}\n");
    }
    if (! $quiet) {
        foreach ($result->notes as $note) {
            fwrite(STDOUT, "registry [{$flavor}]: {$note}\n");
        }
    }
    $failed = $failed || ! $result->ok();
}
if (! $quiet) {
    fwrite(STDOUT, $failed ? "registry: FAILED\n" : ($check ? "registry: check ok\n" : "registry: built\n"));
}
exit($failed ? 1 : 0);
