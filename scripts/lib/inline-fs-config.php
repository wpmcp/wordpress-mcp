<?php

/**
 * Inline the Freemius SDK config into the staged wpmcp-fs.php.
 *
 * Freemius's deployment validator reads the uploaded zip statically and
 * insists that fs_dynamic_init() is called with a literal associative array.
 * The source calls it with Bootstrap::config() so the config stays testable
 * and written once, which the validator rejects ("fs_dynamic_init must be
 * used with associative array with SDK params"), so a zip built from source
 * can never be deployed. This runs on the STAGED copy only, after which the
 * shipped file carries the same values Bootstrap::config() returns.
 *
 * The free and premium self-hosted zips differ in one config value,
 * is_premium, which Bootstrap::config() derives from WPMCP_PREMIUM_BUILD.
 * The constant is defined here, before Bootstrap is loaded, from the third
 * argument: --premium inlines the premium config, no third argument the free
 * one. Defining it in both cases means nothing in the build environment can
 * decide the value.
 *
 * Usage: php inline-fs-config.php <repo-root> <stage-root> [--premium]
 */

$usage = "usage: inline-fs-config.php <repo-root> <stage-root> [--premium]\n";
if ($argc < 3 || $argc > 4 || (4 === $argc && '--premium' !== $argv[3])) {
    fwrite(STDERR, $usage);
    exit(2);
}
[$root, $stage] = [$argv[1], $argv[2]];
$premium        = 4 === $argc;

$main = (string) file_get_contents($root . '/wpmcp.php');
if (
    ! preg_match("/define\( 'WPMCP_FS_ID', (\d+) \);/", $main, $id)
    || ! preg_match("/define\( 'WPMCP_FS_PUBLIC_KEY', '(pk_[A-Za-z0-9]+)' \);/", $main, $key)
) {
    fwrite(STDERR, "could not read WPMCP_FS_ID / WPMCP_FS_PUBLIC_KEY from wpmcp.php\n");
    exit(1);
}

define('ABSPATH', '/');
define('WPMCP_PREMIUM_BUILD', $premium);
define('WPMCP_FS_ID', (int) $id[1]);
define('WPMCP_FS_PUBLIC_KEY', $key[1]);
require $root . '/src/Freemius/Bootstrap.php';

$target = $stage . '/src/Freemius/wpmcp-fs.php';
$source = (string) file_get_contents($target);
$call   = 'fs_dynamic_init(\\WPMCP\\Freemius\\Bootstrap::config())';
if (substr_count($source, $call) !== 1) {
    fwrite(STDERR, "expected exactly one $call in $target\n");
    exit(1);
}

$config = \WPMCP\Freemius\Bootstrap::config();
if (! array_key_exists('is_premium', $config) || $premium !== $config['is_premium']) {
    fwrite(STDERR, sprintf(
        "Bootstrap::config() returned is_premium %s for a %s build; it must follow WPMCP_PREMIUM_BUILD\n",
        var_export($config['is_premium'] ?? null, true),
        $premium ? 'premium' : 'free'
    ));
    exit(1);
}

file_put_contents($target, str_replace($call, 'fs_dynamic_init(' . var_export($config, true) . ')', $source));
