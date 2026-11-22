<?php

/**
 * One module's fast lane (dev only): its Unit and fast conformance tests,
 * with no WordPress (module architecture, addendum section 11.3).
 *
 *   composer test:module -- <Id> [phpunit args]
 *
 * Runs tests/modules/<Id> (or pro/tests/modules/<Id>) under
 * tools/registry/module-test-bootstrap.php. The integration lane
 * (--integration) arrives with the first migrated module.
 */

declare(strict_types=1);

$repo = dirname(__DIR__);
$id = $argv[1] ?? '';
if (1 !== preg_match('/^[A-Z][A-Za-z0-9_]*$/', $id)) {
    fwrite(STDERR, "test:module: give the module folder name, for example Redirects\n");
    exit(2);
}
$dir = null;
foreach (['tests/modules/', 'pro/tests/modules/'] as $root) {
    if (is_dir($repo . '/' . $root . $id)) {
        $dir = $repo . '/' . $root . $id;
        break;
    }
}
if (null === $dir) {
    fwrite(STDERR, "test:module: no tests/modules/{$id} or pro/tests/modules/{$id}\n");
    exit(2);
}
$extra = array_map('escapeshellarg', array_slice($argv, 2));
$cmd = sprintf(
    '%s %s --no-configuration --bootstrap %s %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($repo . '/vendor/bin/phpunit'),
    escapeshellarg($repo . '/tools/registry/module-test-bootstrap.php'),
    implode(' ', $extra),
    escapeshellarg($dir)
);
passthru($cmd, $code);
exit($code);
