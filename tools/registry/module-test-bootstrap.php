<?php

/**
 * Bootstrap for one module's fast lane (composer test:module and the
 * make:module self-check): the fast tier bootstrap (no WordPress), the
 * generator's WordPress stubs, and any extra module roots from
 * WPMCP_MODULE_AUTOLOAD (tools/registry/autoload-env.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload-env.php';
require_once dirname(__DIR__, 2) . '/tests/bootstrap-fast.php';
require_once __DIR__ . '/wp-stubs.php';
