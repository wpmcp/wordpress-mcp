<?php

/**
 * The PHPStan extensions of `composer standard`, found wherever they are
 * installed: the root vendor (once composer.json requires them) or the
 * fallback toolchain in tools/standard/vendor. bleedingEdge comes from the
 * PHPStan in use (the root vendor package or the cached phar).
 */

declare(strict_types=1);

$wpmcp_root = dirname(__DIR__, 2);
$wpmcp_find = static function (string $relative) use ($wpmcp_root): ?string {
    foreach (['/vendor/', '/tools/standard/vendor/'] as $base) {
        if (is_file($wpmcp_root . $base . $relative)) {
            return $wpmcp_root . $base . $relative;
        }
    }
    return null;
};

$wpmcp_includes = [
    is_file($wpmcp_root . '/vendor/phpstan/phpstan/conf/bleedingEdge.neon')
        ? $wpmcp_root . '/vendor/phpstan/phpstan/conf/bleedingEdge.neon'
        : 'phar://phpstan.phar/conf/bleedingEdge.neon',
];
foreach (['szepeviktor/phpstan-wordpress/extension.neon', 'phpstan/phpstan-strict-rules/rules.neon'] as $wpmcp_extension) {
    $wpmcp_file = $wpmcp_find($wpmcp_extension);
    if (null === $wpmcp_file) {
        throw new RuntimeException("PHPStan extension {$wpmcp_extension} is not installed; run `bash bin/standard.sh --install`.");
    }
    $wpmcp_includes[] = $wpmcp_file;
}

return ['includes' => $wpmcp_includes];
