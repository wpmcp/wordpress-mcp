<?php

/**
 * The HTTP mode of the code mode benchmark (issue #1130): the same
 * scenarios, replayed as MCP tools/call requests against a running site,
 * so the bytes are the real response bodies and the times include PHP
 * start-up and the network.
 *
 *   php tools/bench/http.php --url=URL --user=USER --password=APP_PASSWORD \
 *       --wp-path=SITE [--wp-cli=wp] [--scenario=N] [--json=OUT] \
 *       [--save-tools-list=DIR] [--plain-permalinks]
 *
 * Normally started by bin/bench-code-mode.sh --mode=http, which restores the
 * site's exposure mode and prunes the fixtures on exit.
 *
 * --wp-path is the site's WordPress directory, for WP-CLI: the fixtures are
 * seeded and checked there (tools/bench/remote.php), and the exposure mode
 * is switched there to measure each surface's tools/list. Without it only
 * the tools/list of the site's current mode is measured and every scenario
 * is reported as skipped.
 *
 * Scenario 1 refunds an order: the site must enable refunds.create with the
 * wpmcp_woo_op_enabled filter (an mu-plugin), or the run fails at that step.
 * Use a disposable local site: the scenarios write.
 *
 * REPO-ONLY: plain PHP with the curl extension, no WordPress load.
 */

declare(strict_types=1);

namespace WPMCP\Bench;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Scenarios.php';

$opts = getopt('', ['url:', 'user:', 'password:', 'wp-path::', 'wp-cli::', 'scenario::', 'json::', 'save-tools-list::', 'plain-permalinks']);
$opts = is_array($opts) ? $opts : [];
if ('' === Data::str($opts, 'url') || '' === Data::str($opts, 'user') || '' === Data::str($opts, 'password') || ! function_exists('curl_init')) {
    fwrite(STDERR, "usage: php tools/bench/http.php --url=URL --user=USER --password=APP_PASSWORD [--wp-path=SITE] [--wp-cli=wp] [--scenario=N] [--json=OUT] [--save-tools-list=DIR] [--plain-permalinks]\n(needs the curl extension)\n");
    exit(2);
}

final class Http_Client
{
    private string $session = '';
    private int $id         = 1;

    public function __construct(private readonly string $endpoint, private readonly string $auth)
    {
    }

    /**
     * @param array<string, mixed> $message
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function post(array $message): array
    {
        $lines = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: ' . $this->auth, 'MCP-Protocol-Version: 2025-11-25'];
        if ('' !== $this->session) {
            $lines[] = 'Mcp-Session-Id: ' . $this->session;
        }
        $headers = [];
        $ch      = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, Data::json($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $handle, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (2 === count($parts)) {
                $headers[ strtolower(trim($parts[0])) ] = trim($parts[1]);
            }
            return strlen($line);
        });
        $body = curl_exec($ch);
        return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : '', 'headers' => $headers];
    }

    public function initialize(): void
    {
        $init = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
        ]]);
        if (200 !== $init['status']) {
            throw new \RuntimeException(sprintf('initialize answered HTTP %d: %s', $init['status'], substr($init['body'], 0, 300)));
        }
        $this->session = $init['headers']['mcp-session-id'] ?? '';
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /**
     * The JSON-RPC message in a body, which is JSON or a text/event-stream.
     *
     * @return ?array<mixed>
     */
    public static function decode(string $body): ?array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        $lines = preg_split('/\r?\n/', $body);
        foreach (false === $lines ? [] : $lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $json = json_decode(trim(substr($line, 5)), true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        return null;
    }

    /** @return array{bytes: int, tools: int, payload: string} */
    public function tools_list(): array
    {
        $r    = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'tools/list']);
        $list = Data::at(self::decode($r['body']), 'result', 'tools');
        if (! is_array($list)) {
            throw new \RuntimeException(sprintf('tools/list answered HTTP %d: %s', $r['status'], substr($r['body'], 0, 300)));
        }
        return ['bytes' => strlen($r['body']), 'tools' => count($list), 'payload' => $r['body']];
    }

    /**
     * The HTTP transport for Runner: one tools/call, measured as the
     * response body the client receives.
     *
     * @param array<string, mixed> $args
     * @return array{value: mixed, bytes: int, error: ?string}
     */
    public function call(string $ability, array $args): array
    {
        $r     = $this->post(['jsonrpc' => '2.0', 'id' => $this->id++, 'method' => 'tools/call', 'params' => [
            'name'      => str_replace('/', '-', $ability),
            'arguments' => [] === $args ? new \stdClass() : $args,
        ]]);
        $bytes = strlen($r['body']);
        $msg   = self::decode($r['body']);
        if (null === $msg) {
            return ['value' => null, 'bytes' => $bytes, 'error' => sprintf('HTTP %d, not JSON-RPC: %s', $r['status'], substr($r['body'], 0, 200))];
        }
        if (null !== Data::at($msg, 'error')) {
            $message = Data::str($msg, 'error', 'message');
            return ['value' => null, 'bytes' => $bytes, 'error' => '' !== $message ? $message : Data::json($msg['error'])];
        }

        $result = Data::arr($msg, 'result');
        $text   = Data::str($result, 'content', 0, 'text');
        if (Data::truthy($result, 'isError')) {
            return ['value' => null, 'bytes' => $bytes, 'error' => $text];
        }
        $value = Data::at($result, 'structuredContent') ?? json_decode($text, true);

        $error  = null;
        $status = Data::at($value, 'status');
        if (Data::truthy($value, 'error') || [] !== Data::arr($value, 'error')) {
            $error = is_array(Data::at($value, 'error'))
                ? Data::str($value, 'error', 'code') . ': ' . Data::str($value, 'error', 'message')
                : Data::str($value, 'error');
        } elseif (is_int($status) && $status >= 400) {
            $error = 'HTTP ' . $status;
        }

        return ['value' => $value, 'bytes' => $bytes, 'error' => $error];
    }
}

/**
 * Runs WP-CLI on the site and decodes the last JSON line it printed.
 *
 * @param list<string> $args
 * @return ?array<mixed>
 */
function wp_cli(string $wp, string $path, array $args): ?array
{
    $cmd   = escapeshellcmd($wp) . ' --path=' . escapeshellarg($path) . ' ' . implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $args)) . ' 2>&1';
    $lines = [];
    $code  = 0;
    exec($cmd, $lines, $code);
    foreach (array_reverse($lines) as $line) {
        $json = json_decode($line, true);
        if (is_array($json)) {
            return $json;
        }
    }
    fwrite(STDERR, sprintf("wp %s exited %d:\n%s\n", implode(' ', $args), $code, implode("\n", array_slice($lines, -20))));
    return null;
}

$site     = rtrim(Data::str($opts, 'url'), '/');
$endpoint = array_key_exists('plain-permalinks', $opts) ? $site . '/?rest_route=/mcp/wpmcp-server' : $site . '/wp-json/mcp/wpmcp-server';
$client   = new Http_Client($endpoint, 'Basic ' . base64_encode(Data::str($opts, 'user') . ':' . Data::str($opts, 'password')));
$wp_path  = Data::str($opts, 'wp-path');
$wp       = Data::str($opts, 'wp-cli');
$wp       = '' === $wp ? 'wp' : $wp;
$only     = Data::int($opts, 'scenario');
$dump     = Data::str($opts, 'save-tools-list');
$remote   = __DIR__ . '/remote.php';

$client->initialize();

$doc    = [
    'issue'        => 1130,
    'mode'         => 'http',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit'       => (string) getenv('WPMCP_BENCH_COMMIT'),
    'site'         => $site,
    'tokens'       => ['bytes_per_token' => 4.0, 'method' => 'bytes / 4, not calibrated'],
    'methods'      => [
        'tools_list_bytes' => 'the tools/list response body over HTTP',
        'calls'            => 'tool calls the scripted sequence makes',
        'turns'            => 'model round trips: a step whose calls are independent (a fan-out) is one turn',
        'result_bytes'     => 'tools/call response bodies over HTTP, summed (text content and structuredContent)',
        'ms'               => 'wall time of the replay as the client saw it, without the tools/list',
    ],
    'tools_list'   => [],
    'scenarios'    => [],
];
$factor = __DIR__ . '/token-factor.json';
$data   = is_readable($factor) ? json_decode((string) file_get_contents($factor), true) : null;
$per    = Data::float($data, 'bytes_per_token');
if ($per > 0) {
    $measured      = Data::str($data, 'measured_at');
    $doc['tokens'] = ['bytes_per_token' => $per, 'method' => sprintf('bytes / %.2f, calibrated with count_tokens on %s', $per, '' === $measured ? '?' : $measured)];
}

$surfaces = '' === $wp_path ? ['current'] : [Surface::FULL, Surface::COMPACT];
foreach ($surfaces as $surface) {
    if ('current' !== $surface) {
        $ignored = [];
        $code    = 0;
        exec(escapeshellcmd($wp) . ' --path=' . escapeshellarg($wp_path) . ' option update wpmcp_tool_exposure_mode ' . escapeshellarg($surface) . ' 2>&1', $ignored, $code);
        if (0 !== $code) {
            fwrite(STDERR, "could not set the exposure mode to {$surface} through WP-CLI\n");
            exit(1);
        }
    }
    $list = $client->tools_list();
    if ('' !== $dump && is_dir($dump)) {
        file_put_contents($dump . '/tools-list-' . $surface . '.json', $list['payload']);
    }
    $doc['tools_list'][ $surface ] = ['bytes' => $list['bytes'], 'tools' => $list['tools'], 'source' => 'HTTP tools/list response body'];
}

$failed = 0;
foreach (Scenarios::all() as $scenario) {
    if (0 !== $only && $only !== $scenario->id()) {
        continue;
    }
    $id       = (string) $scenario->id();
    $surfaces = [];

    foreach ([Surface::FULL, Surface::COMPACT] as $surface) {
        if ('' === $wp_path) {
            $surfaces[ $surface ] = ['status' => 'skipped', 'reason' => 'needs --wp-path to seed the fixtures'];
            continue;
        }
        $seeded = wp_cli($wp, $wp_path, ['eval-file', $remote, 'seed', $id]);
        if (null === $seeded) {
            $surfaces[ $surface ] = ['status' => 'failed', 'reason' => 'seeding through WP-CLI failed'];
            $failed++;
            continue;
        }
        if (null !== Data::at($seeded, 'skipped')) {
            $surfaces[ $surface ] = ['status' => 'skipped', 'reason' => Data::str($seeded, 'skipped')];
            continue;
        }

        $fixtures = Data::map($seeded, 'fixtures');
        $out      = (new Runner($client->call(...)))->run(Scenarios::sequence($scenario, $fixtures, $surface));

        if ('ok' === $out['status']) {
            $tmp = tempnam(sys_get_temp_dir(), 'wpmcp-bench-');
            if (false === $tmp) {
                throw new \RuntimeException('could not create a temp file for verify');
            }
            file_put_contents($tmp, Data::json(['fixtures' => $fixtures, 'state' => $out['state']]));
            $checked = wp_cli($wp, $wp_path, ['eval-file', $remote, 'verify', $id, $tmp]);
            unlink($tmp);
            if (null === $checked || null !== Data::at($checked, 'problem')) {
                $out['status'] = 'failed';
                $out['reason'] = 'verify: ' . (null === $checked ? 'WP-CLI failed' : Data::str($checked, 'problem'));
            }
        }
        wp_cli($wp, $wp_path, ['eval-file', $remote, 'prune']);

        unset($out['state']);
        $surfaces[ $surface ] = $out;
        if ('failed' === $out['status']) {
            $failed++;
        }
    }
    $doc['scenarios'][] = ['id' => $scenario->id(), 'slug' => $scenario->slug(), 'title' => $scenario->title(), 'surfaces' => $surfaces];
}

$json = Data::json($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$dest = Data::str($opts, 'json');
if ('' !== $dest) {
    file_put_contents($dest, $json);
} else {
    echo $json;
}
exit($failed > 0 ? 1 : 0);
