<?php
/**
 * Decision D8 probe, run by ServerRouteScopeTest in its own PHP process:
 * boot WordPress with this plugin for one request URI, with or without
 * WPMCP_ROUTE_SCOPED_MCP, then build the REST server the way a REST request
 * does. The suite process cannot show this: its bootstrap instantiated the
 * adapter singleton and registered every ability long before any test runs.
 *
 * Usage: php route-scoped-mcp.php <wp-tests-config.php> <repo root> <request uri> <flag 0|1> [<rest_route>|-] [<cli 0|1>]
 * Prints one JSON object:
 *   adapter          whether McpAdapter::instance() ran while WordPress booted
 *   abilities_init   times wp_abilities_api_init fired once rest_api_init ran
 *   route            whether /mcp/wpmcp-server is in the REST route table
 *   error            a Throwable raised by the probe, or null
 */

// phpcs:disable -- a standalone test probe, not plugin code.

if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(2);
}

[, $config, $root, $uri, $flag] = $argv;
$rest_route = (string) ($argv[5] ?? '-');
$cli        = '1' === ($argv[6] ?? '0');

define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
if ('1' === $flag) {
    define('WPMCP_ROUTE_SCOPED_MCP', true);
}
if ($cli) {
    // What Request_Context and the adapter read; WP-CLI's own classes are
    // absent, so the stdio command and the adapter's CLI commands stay unregistered.
    define('WP_CLI', true);
}

$_SERVER['HTTP_HOST']      = 'example.org';
$_SERVER['SERVER_NAME']    = 'example.org';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = $uri;
if ('-' !== $rest_route) {
    $_GET['rest_route'] = $rest_route;
}

// Load the plugin the way the suite bootstrap does (tests_add_filter on
// muplugins_loaded), before WordPress builds its hooks.
$GLOBALS['wp_filter']['muplugins_loaded'][10][] = [
    'function'      => static function () use ($root): void {
        require $root . '/wpmcp.php';
    },
    'accepted_args' => 0,
];

require $config;
require ABSPATH . 'wp-settings.php';

$out = [
    'adapter'        => false,
    'abilities_init' => null,
    'route'          => false,
    'error'          => null,
];

try {
    if (class_exists(\WP\MCP\Core\McpAdapter::class)) {
        $out['adapter'] = (new \ReflectionProperty(\WP\MCP\Core\McpAdapter::class, 'instance'))->isInitialized();
    }
    $routes                = rest_get_server()->get_routes();
    $out['abilities_init'] = did_action('wp_abilities_api_init');
    $out['route']          = isset($routes['/mcp/wpmcp-server']);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo "\n" . json_encode($out);
