<?php

// Fixture registry for the gate rule tests (the shape bin/build-registry.php
// writes to build/registry/boot.php, reduced to what the rules read).

declare(strict_types=1);

return [
    'modules' => [
        'alpha' => ['namespace' => 'WPMCP\\Tests\\Kernel\\PHPStan\\Data\\Alpha\\', 'dir' => 'tests/kernel/PHPStan/Data/Alpha'],
        'beta' => ['namespace' => 'WPMCP\\Tests\\Kernel\\PHPStan\\Data\\Beta\\', 'dir' => 'tests/kernel/PHPStan/Data/Beta'],
    ],
    'ownership' => [
        'namespaces' => [
            'WPMCP\\Tests\\Kernel\\PHPStan\\Data\\Alpha\\' => 'alpha',
            'WPMCP\\Tests\\Kernel\\PHPStan\\Data\\Beta\\' => 'beta',
        ],
        'paths' => [
            'tests/kernel/PHPStan/Data/Legacy/Beta_*.php' => 'beta',
        ],
    ],
];
