<?php

/**
 * The ten scenarios of the benchmark and the CSV row of each request.
 *
 * REPO-ONLY, part of bin/bench (see bin/bench/run.php).
 */

declare(strict_types=1);

namespace WPMCP\Bench;

final class Bench
{
    public const COLUMNS = [
        'label', 'scenario', 'run', 'wpmcp_active', 'http_status', 'location', 'wall_ms', 'server_ms', 'st_app_ms',
        'st_abilities_ms', 'abilities_ms', 'bytes', 'tools', 'rpc_error', 'wpmcp_files', 'wpmcp_bytes',
        'wpmcp_src_files', 'wpmcp_src_bytes', 'plugin_php_loaded', 'plugin_php_bytes', 'freemius_files',
        'freemius_bytes', 'vendor_other_files', 'vendor_other_bytes', 'all_files', 'classes',
        'abilities_registered', 'wpmcp_abilities_registered', 'abilities_init_fired', 'peak_mem',
        'mem_plugins_loaded', 'ms_plugins_loaded', 'ms_wp_loaded', 'php', 'opcache', 'probe',
    ];

    private const SCENARIOS = [
        'S1'  => 'front-end home',
        'S2'  => 'single post',
        'S3'  => 'wp-admin dashboard',
        'S4'  => 'REST /wp/v2/posts?per_page=1',
        'S5'  => 'Store API cart',
        'S6'  => 'MCP initialize',
        'S7'  => 'MCP tools/list full',
        'S8'  => 'MCP tools/list compact',
        'S9'  => 'tools/call get-site-context',
        'S10' => 'tools/call plugin-data-read list-operations',
    ];

    private Http $http;

    private ?Probe_Log $log;

    private string $session = '';

    private int $rpc_id = 1;

    /** @var array<int, array<string, scalar|null>> */
    private array $rows = [];

    /** @var resource|null */
    private $csv = null;

    /**
     * @param array<int, string> $scenarios
     */
    public function __construct(
        private readonly string $site,
        private readonly string $user,
        private readonly string $app_password,
        private readonly string $login_password,
        private readonly string $label,
        private readonly array $scenarios,
        private readonly bool $delta,
        private readonly bool $plain,
        private string $post_url,
        ?string $probe_log,
        ?string $out
    ) {
        $this->http = new Http();
        $this->log  = null !== $probe_log && '' !== $probe_log ? new Probe_Log($probe_log) : null;
        if (null !== $out && '' !== $out) {
            $fh = fopen($out, 'wb');
            if (false === $fh) {
                throw new \RuntimeException('cannot write ' . $out);
            }
            $this->csv = $fh;
            fputcsv($fh, self::COLUMNS, ",", "\"", "");
        }
    }

    public static function scenario_name(string $id): string
    {
        return self::SCENARIOS[ $id ] ?? $id;
    }

    /** @return array<int, string> */
    public static function scenario_ids(): array
    {
        return array_keys(self::SCENARIOS);
    }

    public function prepare(): void
    {
        if ('' === $this->post_url) {
            $r    = $this->http->request('GET', $this->rest('/wp/v2/posts', 'per_page=1&_fields=link'));
            $list = json_decode($r['body'], true);
            if (is_array($list) && isset($list[0]['link']) && is_string($list[0]['link'])) {
                $this->post_url = $list[0]['link'];
            }
        }
        if (in_array('S3', $this->scenarios, true) && '' !== $this->login_password) {
            $login = $this->http->request(
                'POST',
                $this->site . '/wp-login.php',
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check'],
                http_build_query(['log' => $this->user, 'pwd' => $this->login_password, 'rememberme' => 'forever', 'testcookie' => '1', 'redirect_to' => $this->site . '/wp-admin/'])
            );
            $this->http->keep_cookies($login['set_cookies']);
            if (! $this->http->has_cookies() || 302 !== $login['status']) {
                fwrite(STDERR, sprintf("wp-admin login answered HTTP %d without a session cookie; S3 is skipped\n", $login['status']));
            }
            // Plugins send the first wp-admin visits after activation to
            // their welcome screens (Elementor onboarding, the Polylang
            // wizard), one redirect each; use them up before measuring.
            for ($i = 0; $i < 8; $i++) {
                $visit = $this->http->request('GET', $this->site . '/wp-admin/', [], null, true);
                if (302 !== $visit['status']) {
                    break;
                }
                $to = (string) ($visit['headers']['location'] ?? '');
                if (str_starts_with($to, $this->site . '/wp-admin/')) {
                    $this->http->request('GET', $to, [], null, true);
                }
            }
            if (200 !== $visit['status']) {
                fwrite(STDERR, sprintf("wp-admin still answers HTTP %d after %d visits\n", $visit['status'], $i + 1));
            }
        }
    }

    public function run(int $runs, int $warmup): void
    {
        for ($i = 1 - $warmup; $i <= $runs; $i++) {
            $this->session = '';
            foreach ($this->scenarios as $id) {
                $this->scenario($id, $i, true);
                if ($this->delta && in_array($id, ['S1', 'S2', 'S3', 'S4', 'S5'], true)) {
                    $this->scenario($id, $i, false);
                }
            }
        }
    }

    /** @return array<int, array<string, scalar|null>> */
    public function rows(): array
    {
        return $this->rows;
    }

    private function scenario(string $id, int $run, bool $active): void
    {
        $bench_id = sprintf('%s-%s-%d-%d-%s', $this->label, $id, $run, $active ? 1 : 0, bin2hex(random_bytes(4)));
        $headers  = ['X-WPMCP-Bench-Id' => $bench_id];
        if (! $active) {
            $headers['X-WPMCP-Bench-Inactive'] = '1';
        }

        $r = match ($id) {
            'S1'    => $this->http->request('GET', $this->site . '/', $headers),
            'S2'    => '' === $this->post_url ? null : $this->http->request('GET', $this->post_url, $headers),
            'S3'    => $this->http->has_cookies() ? $this->http->request('GET', $this->site . '/wp-admin/', $headers, null, true) : null,
            'S4'    => $this->http->request('GET', $this->rest('/wp/v2/posts', 'per_page=1'), $headers),
            'S5'    => $this->http->request('GET', $this->rest('/wc/store/v1/cart'), $headers),
            'S6'    => $this->initialize($headers),
            'S7'    => $this->rpc($headers, 'tools/list', null),
            'S8'    => $this->rpc($headers + ['X-WPMCP-Bench-Exposure' => 'compact'], 'tools/list', null),
            'S9'    => $this->rpc($headers, 'tools/call', ['name' => 'wpmcp-get-site-context', 'arguments' => new \stdClass()]),
            'S10'   => $this->rpc($headers, 'tools/call', ['name' => 'wpmcp-plugin-data-read', 'arguments' => ['operation' => 'list-operations']]),
            default => null,
        };
        if (null === $r || $run < 1) {
            return;
        }

        $probe  = $this->log?->find($bench_id);
        $source = null === $probe ? 'none' : 'log';
        if (null === $probe && isset($r['headers']['x-wpmcp-bench'])) {
            $decoded = json_decode($r['headers']['x-wpmcp-bench'], true);
            if (is_array($decoded)) {
                $probe  = $decoded;
                $source = 'header-' . (string) ($decoded['phase'] ?? '');
            }
        }

        $tools     = '';
        $rpc_error = '';
        if (in_array($id, ['S6', 'S7', 'S8', 'S9', 'S10'], true)) {
            $msg = decode_rpc($r['body']);
            if (! is_array($msg)) {
                $rpc_error = 'not JSON-RPC';
            } elseif (isset($msg['error'])) {
                $rpc_error = substr((string) ($msg['error']['message'] ?? 'error'), 0, 80);
            } elseif (! empty($msg['result']['isError'])) {
                $rpc_error = 'isError';
            }
            if (is_array($msg['result']['tools'] ?? null)) {
                $tools = count($msg['result']['tools']);
            }
        }

        $timing = server_timing($r['headers']['server-timing'] ?? '');
        $files  = (array) ($probe['files'] ?? []);
        $ms     = (array) ($probe['ms'] ?? []);
        $mem    = (array) ($probe['mem'] ?? []);
        $abil   = (array) ($probe['abilities'] ?? []);
        $row    = [
            'label'                      => $this->label,
            'scenario'                   => $id,
            'run'                        => $run,
            'wpmcp_active'               => $active ? 1 : 0,
            'http_status'                => $r['status'],
            'location'                   => preg_replace('#^https?://[^/]+#', '', $r['headers']['location'] ?? ''),
            'wall_ms'                    => round($r['wall_ms'], 2),
            'server_ms'                  => $ms['shutdown'] ?? '',
            'st_app_ms'                  => $timing['app'] ?? '',
            'st_abilities_ms'            => $timing['abilities'] ?? '',
            'abilities_ms'               => $abil['ms'] ?? '',
            'bytes'                      => strlen($r['body']),
            'tools'                      => $tools,
            'rpc_error'                  => $rpc_error,
            'wpmcp_files'                => $files['wpmcp'] ?? '',
            'wpmcp_bytes'                => $files['wpmcp_bytes'] ?? '',
            'wpmcp_src_files'            => $files['src'] ?? '',
            'wpmcp_src_bytes'            => $files['src_bytes'] ?? '',
            'plugin_php_loaded'          => $files['plugin_php'] ?? '',
            'plugin_php_bytes'           => $files['plugin_php_bytes'] ?? '',
            'freemius_files'             => $files['freemius'] ?? '',
            'freemius_bytes'             => $files['freemius_bytes'] ?? '',
            'vendor_other_files'         => $files['vendor_other'] ?? '',
            'vendor_other_bytes'         => $files['vendor_other_bytes'] ?? '',
            'all_files'                  => $files['all'] ?? '',
            'classes'                    => $probe['classes'] ?? '',
            'abilities_registered'       => $abil['registered'] ?? '',
            'wpmcp_abilities_registered' => $abil['wpmcp'] ?? '',
            'abilities_init_fired'       => isset($abil['init_fired']) ? (int) $abil['init_fired'] : '',
            'peak_mem'                   => $probe['peak_mem'] ?? '',
            'mem_plugins_loaded'         => $mem['plugins_loaded'] ?? '',
            'ms_plugins_loaded'          => $ms['plugins_loaded'] ?? '',
            'ms_wp_loaded'               => $ms['wp_loaded'] ?? '',
            'php'                        => $probe['php'] ?? '',
            'opcache'                    => isset($probe['opcache']) ? (int) $probe['opcache'] : '',
            'probe'                      => $source,
        ];
        $this->rows[] = $row;
        if (null !== $this->csv) {
            fputcsv($this->csv, array_map(static fn ($v) => null === $v ? '' : (string) $v, array_values($row)), ",", "\"", "");
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>, wall_ms: float, set_cookies: array<int, string>}
     */
    private function initialize(array $headers): array
    {
        $r = $this->rpc($headers, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
        ], false);
        $this->session = $r['headers']['mcp-session-id'] ?? '';
        $this->rpc(['X-WPMCP-Bench-Id' => 'notify-' . bin2hex(random_bytes(4))], 'notifications/initialized', null, true, true);

        return $r;
    }

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $params
     * @return array{status: int, body: string, headers: array<string, string>, wall_ms: float, set_cookies: array<int, string>}
     */
    private function rpc(array $headers, string $method, ?array $params, bool $with_session = true, bool $notification = false): array
    {
        if ($with_session && '' === $this->session && 'notifications/initialized' !== $method) {
            $this->initialize(['X-WPMCP-Bench-Id' => 'init-' . bin2hex(random_bytes(4))]);
        }
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if (! $notification) {
            $message['id'] = $this->rpc_id++;
        }
        if (null !== $params) {
            $message['params'] = $params;
        }
        $headers += [
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json, text/event-stream',
            'Authorization'        => 'Basic ' . base64_encode($this->user . ':' . $this->app_password),
            'MCP-Protocol-Version' => '2025-11-25',
        ];
        if ($with_session && '' !== $this->session) {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->http->request('POST', $this->rest('/mcp/wpmcp-server'), $headers, (string) json_encode($message));
    }

    private function rest(string $route, string $query = ''): string
    {
        if ($this->plain) {
            return $this->site . '/?rest_route=' . $route . ('' === $query ? '' : '&' . $query);
        }
        return $this->site . '/wp-json' . $route . ('' === $query ? '' : '?' . $query);
    }
}
