<?php

/**
 * Shared code of bin/bench/run.php and bin/bench/compare.php: the HTTP
 * client, the probe log reader, the scenarios and the percentile.
 *
 * REPO-ONLY: plain PHP with the curl extension, no WordPress load.
 */

declare(strict_types=1);

namespace WPMCP\Bench;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Probe_Log.php';
require_once __DIR__ . '/Bench.php';

/** @return array<string, float> Server-Timing metrics by name. */
function server_timing(string $header): array
{
    $metrics = [];
    foreach (explode(',', $header) as $metric) {
        if (preg_match('/^\s*([a-z-]+);dur=([0-9.]+)/i', $metric, $m)) {
            $metrics[ $m[1] ] = (float) $m[2];
        }
    }
    return $metrics;
}

/** The JSON-RPC message in a body, which is JSON or a text/event-stream. */
function decode_rpc(string $body): ?array
{
    $json = json_decode($body, true);
    if (is_array($json)) {
        return $json;
    }
    foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
        if (str_starts_with($line, 'data:')) {
            $json = json_decode(trim(substr($line, 5)), true);
            if (is_array($json)) {
                return $json;
            }
        }
    }
    return null;
}

/**
 * Nearest-rank percentile of the numeric values.
 *
 * @param array<int, mixed> $values
 */
function percentile(array $values, float $p): ?float
{
    $values = array_values(array_map('floatval', array_filter($values, static fn ($v) => is_numeric($v))));
    if ([] === $values) {
        return null;
    }
    sort($values);
    $rank = (int) ceil($p / 100 * count($values));

    return $values[ max(0, min(count($values) - 1, $rank - 1)) ];
}
