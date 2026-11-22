<?php

/**
 * Autoloading for `composer standard` (bin/standard.sh passes this file to
 * PHPStan as --autoload-file): the WPMCP\PHPStan\ rules, and the fallback
 * toolchain in tools/standard/vendor (PHPStan extensions and slevomat), which
 * PHPStan must be able to load before it builds its container.
 */

declare(strict_types=1);

if (is_file(dirname(__DIR__) . '/standard/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/standard/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'WPMCP\\PHPStan\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
