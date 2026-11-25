#!/usr/bin/env php
<?php

/**
 * The WPMCP request benchmark (module architecture, section 3.6 and
 * acceptance criteria A1): ten scenarios over HTTP against a running site,
 * one CSV row per request.
 *
 *   php bin/bench/run.php --url=URL --user=USER --password=APP_PASSWORD \
 *       [--login-password=PASSWORD] [--runs=30] [--warmup=3] [--label=before|after] \
 *       [--out=file.csv] [--probe-log=FILE] [--delta] [--scenarios=S1,S7] \
 *       [--plain-permalinks] [--post-url=URL]
 *
 * Normally started by bin/bench/env.sh, which builds a throwaway site, runs
 * this and removes everything again. Against your own site: copy
 * bin/bench/probe.php into wp-content/mu-plugins, define WPMCP_BENCH as true
 * in wp-config.php and pass --probe-log with the path of the log it writes
 * (WPMCP_BENCH_LOG); without the log, the columns the probe fills come from
 * its X-WPMCP-Bench header where the request could still send one, and are
 * empty otherwise.
 *
 * --password is an application password (MCP and REST); it may come from
 * WPMCP_BENCH_APP_PASSWORD instead. --login-password is the account
 * password, for the wp-admin scenario (a cookie login), or
 * WPMCP_BENCH_LOGIN_PASSWORD; without it S3 is skipped.
 *
 * Scenarios, run in this order on every run so drift spreads over all:
 *   S1 front-end home         S6 MCP initialize
 *   S2 a single post          S7 full tools/list
 *   S3 wp-admin dashboard     S8 compact tools/list (X-WPMCP-Bench-Exposure)
 *   S4 /wp/v2/posts?per_page=1  S9 tools/call get-site-context
 *   S5 Store API cart         S10 tools/call plugin-data-read list-operations
 *
 * --delta repeats S1 to S5 on every run with X-WPMCP-Bench-Inactive: 1, so
 * the probe drops WPMCP from active_plugins for that request: the rows with
 * wpmcp_active=0 are the same requests without WPMCP, and compare.php
 * reports active minus inactive (the A1 delta). The MCP scenarios have no
 * inactive counterpart.
 *
 * Bytes are the response bodies as received (no compression asked for), as
 * in the code mode benchmark of #1130 (tools/bench/http.php), whose JSON or
 * event-stream decoding this repeats; every scenario is one round trip.
 *
 * REPO-ONLY: plain PHP with the curl extension, no WordPress load.
 */

declare(strict_types=1);

namespace WPMCP\Bench;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/lib.php';

$opts = getopt('', [
    'url:', 'user:', 'password::', 'login-password::', 'runs::', 'warmup::', 'label::', 'out::',
    'probe-log::', 'delta', 'scenarios::', 'plain-permalinks', 'post-url::',
]);
$app_password   = (string) ($opts['password'] ?? getenv('WPMCP_BENCH_APP_PASSWORD'));
$login_password = (string) ($opts['login-password'] ?? getenv('WPMCP_BENCH_LOGIN_PASSWORD'));
if (! isset($opts['url'], $opts['user']) || '' === $app_password || ! function_exists('curl_init')) {
    fwrite(STDERR, "usage: php bin/bench/run.php --url=URL --user=USER --password=APP_PASSWORD [--login-password=PASSWORD]\n"
        . "       [--runs=30] [--warmup=3] [--label=before] [--out=file.csv] [--probe-log=FILE] [--delta]\n"
        . "       [--scenarios=S1,S2,...] [--plain-permalinks] [--post-url=URL]\n"
        . "(needs the curl extension; the passwords may come from WPMCP_BENCH_APP_PASSWORD and WPMCP_BENCH_LOGIN_PASSWORD)\n");
    exit(2);
}

$scenarios = isset($opts['scenarios']) ? array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $opts['scenarios']))))) : Bench::scenario_ids();
$unknown   = array_diff($scenarios, Bench::scenario_ids());
if ([] !== $unknown) {
    fwrite(STDERR, 'unknown scenario(s): ' . implode(', ', $unknown) . "\n");
    exit(2);
}

$bench = new Bench(
    rtrim((string) $opts['url'], '/'),
    (string) $opts['user'],
    $app_password,
    $login_password,
    (string) ($opts['label'] ?? 'before'),
    $scenarios,
    isset($opts['delta']),
    isset($opts['plain-permalinks']),
    (string) ($opts['post-url'] ?? ''),
    isset($opts['probe-log']) ? (string) $opts['probe-log'] : null,
    isset($opts['out']) ? (string) $opts['out'] : null
);
$bench->prepare();
$bench->run(max(1, (int) ($opts['runs'] ?? 30)), max(0, (int) ($opts['warmup'] ?? 3)));

$groups = [];
foreach ($bench->rows() as $row) {
    $groups[ $row['scenario'] . ($row['wpmcp_active'] ? '' : ' (inactive)') ][] = $row;
}
printf("%-46s %6s %9s %9s %9s %8s %6s %6s %9s\n", 'scenario (p50)', 'status', 'wall_ms', 'server', 'bytes', 'src', 'fs', 'abil', 'peak_MB');
foreach ($groups as $name => $rows) {
    $id = explode(' ', $name, 2)[0];
    printf(
        "%-46s %6s %9.1f %9s %9d %8s %6s %6s %9s\n",
        $name . ' ' . Bench::scenario_name($id),
        (string) $rows[0]['http_status'],
        (float) percentile(array_column($rows, 'wall_ms'), 50),
        (string) (percentile(array_column($rows, 'server_ms'), 50) ?? '-'),
        (int) percentile(array_column($rows, 'bytes'), 50),
        (string) (percentile(array_column($rows, 'wpmcp_src_files'), 50) ?? '-'),
        (string) (percentile(array_column($rows, 'freemius_files'), 50) ?? '-'),
        (string) (percentile(array_column($rows, 'wpmcp_abilities_registered'), 50) ?? '-'),
        null === ($peak = percentile(array_column($rows, 'peak_mem'), 50)) ? '-' : sprintf('%.1f', $peak / 1048576)
    );
}
