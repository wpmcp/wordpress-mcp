<?php

/**
 * One golden capture probe: a fresh PHP process for one flavor and request
 * context. Started by Capture::files() (tests/support/golden/capture.php),
 * never by hand outside a test run, because it boots the test environment
 * against the database the running PHPUnit process configured.
 *
 *   php probe.php --flavor=<full|woocommerce|wporg> --context=<frontend|admin|ajax|cron|rest|cli>
 *                 --root=<plugin tree> --checkout=<repository> --out=<report.json>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/capture.php';

$wpmcp_golden_args = [];
foreach (array_slice($argv, 1) as $wpmcp_golden_arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $wpmcp_golden_arg, $wpmcp_golden_m)) {
        $wpmcp_golden_args[ $wpmcp_golden_m[1] ] = $wpmcp_golden_m[2];
    }
}
// The WordPress test bootstrap reads $_SERVER['argv'] for PHPUnit options.
$_SERVER['argv'] = [ $argv[0] ];

\WPMCP\Tests\Support\Golden\Capture::probe($wpmcp_golden_args);
