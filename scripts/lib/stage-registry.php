<?php

/**
 * The module half of a release stage (D11): after build-registry.php wrote
 * <stage>/build/registry for the flavor, drop every module folder the flavor
 * does not ship and check that every module it does ship is in the stage.
 * Modules leave a flavor by their own #[Module(flavors:, exclude_flavors:)]
 * declaration, never by a line in strip.php or policy.php.
 *
 *   php scripts/lib/stage-registry.php <stage>
 *
 * Exit codes: 0 ok, 1 a shipped module is missing from the stage, 2 bad usage.
 */

declare(strict_types=1);

$wpmcp_stage = rtrim($argv[1] ?? '', '/');
$wpmcp_boot = $wpmcp_stage . '/build/registry/boot.php';
if ('' === $wpmcp_stage || ! is_file($wpmcp_boot)) {
    fwrite(STDERR, "usage: php scripts/lib/stage-registry.php <stage> (with <stage>/build/registry/boot.php written)\n");
    exit(2);
}
$wpmcp_registry = require $wpmcp_boot;
$wpmcp_shipped = [];
foreach ((array) (is_array($wpmcp_registry) ? ($wpmcp_registry['modules'] ?? []) : []) as $wpmcp_id => $wpmcp_module) {
    $wpmcp_shipped[ trim((string) (((array) $wpmcp_module)['dir'] ?? ''), '/') ] = (string) $wpmcp_id;
}

$wpmcp_failed = false;
foreach ($wpmcp_shipped as $wpmcp_dir => $wpmcp_id) {
    if (! is_dir($wpmcp_stage . '/' . $wpmcp_dir)) {
        fwrite(STDERR, "stage registry: module {$wpmcp_id} ships in this flavor but {$wpmcp_dir} is not in the stage\n");
        $wpmcp_failed = true;
    }
}

$wpmcp_rm = static function (string $path) use (&$wpmcp_rm): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ('.' !== $entry && '..' !== $entry) {
            $wpmcp_rm($path . '/' . $entry);
        }
    }
    rmdir($path);
};
foreach (['src/Modules', 'pro/modules'] as $wpmcp_root) {
    foreach ((array) glob($wpmcp_stage . '/' . $wpmcp_root . '/*', GLOB_ONLYDIR) as $wpmcp_path) {
        $wpmcp_relative = $wpmcp_root . '/' . basename((string) $wpmcp_path);
        if (! isset($wpmcp_shipped[ $wpmcp_relative ])) {
            $wpmcp_rm((string) $wpmcp_path);
            echo "stage registry: dropped {$wpmcp_relative} (not in this flavor)\n";
        }
    }
}
exit($wpmcp_failed ? 1 : 0);
