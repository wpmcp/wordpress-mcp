<?php
/**
 * The footprint probe of tests/free/Perf/FootprintTest.php (module
 * architecture, section 3.6): one fresh PHP process per request context,
 * booting WordPress and WP MCP the way that request does, then printing what
 * WP MCP loaded (bin/bench/probe.php's Probe::snapshot()).
 *
 * The suite's own process cannot show this: it has autoloaded most of src/
 * long before a test runs. So each context runs here, against tables of its
 * own (the table prefix is the fourth argument) in the test database, never
 * the suite's tables: what WP MCP writes while it boots (seeded options,
 * Freemius state, transients) stays out of the suite, and the test drops the
 * tables afterwards.
 *
 * WP MCP is the only plugin loaded, from the repository, at
 * muplugins_loaded like tests/bootstrap.php loads it, so the counts depend
 * on WP MCP's code alone and not on the versions of the third-party test
 * plugins. bin/bench/run.php measures the same counts with the canonical
 * plugins active.
 *
 * Usage: php footprint.php <wp-tests-config.php> <repo root> <mode> <table prefix>
 *   install   wp_install() into the prefixed tables
 *   activate  WP MCP's activation hook (its tables and options)
 *   front     the front-end home page: wp() and the template loader
 *   admin     wp-admin/index.php as an administrator, approximated (see below)
 *   rest      GET ?rest_route=/wp/v2/posts&per_page=1 through wp(), unauthenticated
 *   mcp       MCP initialize, then tools/list, as an administrator
 *   drop      drop every table with the prefix
 * Prints one line: FOOTPRINT <json>; the mcp context also prints
 * FOOTPRINT_MCP <tools/list HTTP status> <tool count>.
 *
 * The admin context cannot require wp-admin/index.php: its wp-load.php looks
 * for a wp-config.php next to the test core, and the test core has none. It
 * replays what wp-admin/admin.php and index.php do that a plugin can hook:
 * wp-admin/includes/admin.php, admin_init, the admin menu (admin_menu),
 * the dashboard screen (current_screen), admin_enqueue_scripts, the admin
 * head and notices, the dashboard widgets (wp_dashboard_setup) and the
 * footer actions.
 */

// phpcs:disable -- a standalone test probe, not plugin code.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(2);
}

[, $config, $root, $mode, $prefix] = $argv;
if (! preg_match('/^[a-z0-9_]+_$/', $prefix)) {
    exit(2);
}

define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'wpmcp-footprint';

if ('drop' === $mode) {
    require $config;
    [$host, $port] = array_pad(explode(':', DB_HOST, 2), 2, null);
    $socket        = null !== $port && ! is_numeric($port) ? $port : null;
    $db            = mysqli_connect($host, DB_USER, DB_PASSWORD, DB_NAME, null === $socket && null !== $port ? (int) $port : 3306, $socket);
    $like          = str_replace('_', '\\_', $prefix) . '%';
    $result        = mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $like) . "'");
    $dropped       = 0;
    foreach (mysqli_fetch_all($result) as $row) {
        mysqli_query($db, 'DROP TABLE IF EXISTS `' . str_replace('`', '', $row[0]) . '`');
        $dropped++;
    }
    echo 'FOOTPRINT ' . json_encode(['dropped' => $dropped]) . "\n";
    exit(0);
}

// Hooks before WordPress loads, the way the test library adds them.
$GLOBALS['wp_filter'] = [];
$wpmcp_footprint_add  = static function (string $hook, callable $fn, int $priority = 10, int $args = 1): void {
    $GLOBALS['wp_filter'][ $hook ][ $priority ][] = ['function' => $fn, 'accepted_args' => $args];
};
// No network, and no mail from wp_install().
$wpmcp_footprint_add('pre_http_request', static fn () => new \WP_Error('http_request_failed', 'blocked by the footprint probe'), PHP_INT_MAX);
$wpmcp_footprint_add('pre_wp_mail', static fn () => true);

if ('install' === $mode) {
    define('WP_INSTALLING', true);
    require $config;
    $table_prefix = $prefix;
    require ABSPATH . 'wp-settings.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install('Footprint', 'admin', 'admin@example.org', true, '', 'password');
    echo 'FOOTPRINT ' . json_encode(['user_id' => (int) $result['user_id']]) . "\n";
    exit(0);
}

$admin = in_array($mode, ['admin', 'mcp', 'activate'], true);
if ($admin) {
    $wpmcp_footprint_add('determine_current_user', static fn () => 1, PHP_INT_MAX);
}
if ('admin' === $mode) {
    define('WP_ADMIN', true);
    $_SERVER['REQUEST_URI'] = '/wp-admin/index.php';
    $_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
    $_SERVER['PHP_SELF']    = '/wp-admin/index.php';
}
if ('rest' === $mode) {
    $_GET['rest_route']     = '/wp/v2/posts';
    $_GET['per_page']       = '1';
    $_SERVER['REQUEST_URI'] = '/?rest_route=/wp/v2/posts&per_page=1';
}
if ('mcp' === $mode) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/?rest_route=/mcp/wpmcp-server';
}
if ('front' === $mode) {
    define('WP_USE_THEMES', true);
}

$wpmcp_footprint_add('muplugins_loaded', static function () use ($root): void {
    require_once $root . '/bin/bench/probe.php';
    \WPMCP\Bench\Probe::count_abilities();
    require $root . '/wpmcp.php';
    add_action('shutdown', static function (): void {
        echo "\nFOOTPRINT " . json_encode(\WPMCP\Bench\Probe::snapshot('footprint')) . "\n";
    }, PHP_INT_MAX);
});

require $config;
$table_prefix = $prefix;
ob_start();
require ABSPATH . 'wp-settings.php';

switch ($mode) {
    case 'activate':
        do_action('activate_' . plugin_basename(WPMCP_FILE), false);
        break;

    case 'front':
        wp();
        require ABSPATH . WPINC . '/template-loader.php';
        break;

    case 'rest':
        wp();
        break;

    case 'admin':
        global $pagenow, $hook_suffix, $typenow, $taxnow, $plugin_page;
        $pagenow     = 'index.php';
        $hook_suffix = 'index.php';
        $typenow     = '';
        $taxnow      = '';
        $plugin_page = null;
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        do_action('admin_init');
        require ABSPATH . 'wp-admin/menu.php';
        set_current_screen('dashboard');
        do_action('admin_enqueue_scripts', $hook_suffix);
        do_action('admin_print_styles');
        do_action('admin_print_scripts');
        do_action('admin_head');
        do_action('admin_notices');
        do_action('all_admin_notices');
        require_once ABSPATH . 'wp-admin/includes/dashboard.php';
        wp_dashboard_setup();
        do_action('admin_footer', '');
        do_action('admin_print_footer_scripts');
        break;

    case 'mcp':
        define('REST_REQUEST', true);
        $send = static function (array $message, string $session = ''): \WP_REST_Response {
            $request = new \WP_REST_Request('POST', '/mcp/wpmcp-server');
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Accept', 'application/json, text/event-stream');
            if ('' !== $session) {
                $request->set_header('Mcp-Session-Id', $session);
            }
            $request->set_body((string) json_encode($message));
            // rest_post_dispatch as WP_REST_Server::serve_request() applies it: the
            // adapter sends the session id through that filter.
            return rest_ensure_response(apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), rest_get_server(), $request));
        };
        $init    = $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'wpmcp-footprint', 'version' => '1'],
        ]]);
        $headers = array_change_key_case($init->get_headers(), CASE_LOWER);
        $session = (string) ($headers['mcp-session-id'] ?? '');
        $list    = $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
        $data    = json_decode((string) wp_json_encode($list->get_data()), true);
        $tools   = is_array($data) && is_array($data['result']['tools'] ?? null) ? count($data['result']['tools']) : -1;
        fwrite(STDERR, sprintf("\nFOOTPRINT_MCP %d %d\n", $list->get_status(), $tools));
        break;
}

ob_end_flush();
exit(0);
