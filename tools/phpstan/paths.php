<?php

/**
 * The paths a full `composer standard` run analyses: the gate rules
 * themselves, plus each v2 root that exists (src/Kernel, src/Api,
 * src/Modules, pro/modules). PHPStan refuses a missing path, and the v2
 * roots land on main one by one. A `--diff` run passes its changed files on
 * the command line instead.
 */

declare(strict_types=1);

$wpmcp_root = dirname(__DIR__, 2);

return [
    'parameters' => [
        'excludePaths' => ['analyseAndScan' => [$wpmcp_root . '/tools/standard/vendor']],
        'paths' => array_values(array_filter(
            [
                $wpmcp_root . '/tools/phpstan/src',
                $wpmcp_root . '/tools/standard',
                $wpmcp_root . '/src/Kernel',
                $wpmcp_root . '/src/Api',
                $wpmcp_root . '/src/Modules',
                $wpmcp_root . '/pro/modules',
            ],
            'is_dir',
        )),
    ],
];
