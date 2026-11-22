<?php

/**
 * PHPUnit bootstrap for the gate rule tests (tests/kernel/PHPStan), run by
 * bin/standard.sh: the fast tier bootstrap, then PHPStan's own classes from
 * the PHPStan in use (root vendor, or the cached phar named in
 * WPMCP_PHPSTAN_PHAR), the rules, and the WPMCP\Tests\Kernel\ test namespace.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/bootstrap-fast.php';

$wpmcp_phar = (string) getenv('WPMCP_PHPSTAN_PHAR');
if (! class_exists(\PHPStan\Testing\RuleTestCase::class)) {
    if ('' === $wpmcp_phar || ! is_file($wpmcp_phar)) {
        fwrite(STDERR, "No PHPStan: set WPMCP_PHPSTAN_PHAR or run `bash bin/standard.sh --install`.\n");
        exit(1);
    }
    require_once 'phar://' . $wpmcp_phar . '/vendor/autoload.php';
}
require_once __DIR__ . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'WPMCP\\Tests\\Kernel\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/tests/kernel/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
