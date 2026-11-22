<?php

/**
 * Extra PSR-4 roots from WPMCP_MODULE_AUTOLOAD="Prefix\=dir;Prefix\=dir", for a
 * module outside the composer autoload map (an add-on, or a scaffold in a
 * test fixture root). Loaded by tools/registry/module-test-bootstrap.php and
 * prepended to bin/build-registry.php by bin/make-module.php.
 */

declare(strict_types=1);

foreach (array_filter(explode(';', (string) getenv('WPMCP_MODULE_AUTOLOAD'))) as $wpmcp_pair) {
    [$wpmcp_prefix, $wpmcp_dir] = array_pad(explode('=', $wpmcp_pair, 2), 2, '');
    $wpmcp_prefix = trim($wpmcp_prefix, '\\') . '\\';
    $wpmcp_dir = rtrim($wpmcp_dir, '/');
    spl_autoload_register(static function (string $class) use ($wpmcp_prefix, $wpmcp_dir): void {
        if (str_starts_with($class, $wpmcp_prefix)) {
            $file = $wpmcp_dir . '/' . str_replace('\\', '/', substr($class, strlen($wpmcp_prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}
unset($wpmcp_pair, $wpmcp_prefix, $wpmcp_dir);
