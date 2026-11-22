#!/usr/bin/env php
<?php

/**
 * Markdown tables from bin/bench/run.php CSVs: p50 and p95 per scenario and
 * metric.
 *
 *   php bin/bench/compare.php before.csv after.csv     before, after and the change
 *   php bin/bench/compare.php baseline.csv             one run on its own
 *
 * When a CSV holds rows measured with WP MCP inactive (run.php --delta), a
 * second table gives the delta of acceptance criteria A1: the p50 with WP MCP
 * active minus the p50 with it inactive, per scenario, for the same CSV.
 *
 * REPO-ONLY: plain PHP, no WordPress load.
 */

declare(strict_types=1);

namespace WPMCP\Bench;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/lib.php';

final class Compare
{
    /** Metric column => label, in table order. */
    public const METRICS = [
        'server_ms'                  => 'server ms',
        'wall_ms'                    => 'wall ms',
        'st_app_ms'                  => 'Server-Timing app ms',
        'abilities_ms'               => 'abilities init ms',
        'bytes'                      => 'bytes',
        'wpmcp_src_files'            => 'WPMCP src files',
        'wpmcp_src_bytes'            => 'WPMCP src bytes',
        'plugin_php_loaded'          => 'Plugin.php loaded',
        'freemius_files'             => 'Freemius files',
        'vendor_other_files'         => 'other vendor files',
        'classes'                    => 'WPMCP classes',
        'wpmcp_abilities_registered' => 'WPMCP abilities registered',
        'abilities_registered'       => 'abilities registered (all)',
        'peak_mem'                   => 'peak memory bytes',
    ];

    public const DELTA_METRICS = ['server_ms', 'wall_ms', 'peak_mem', 'all_files'];

    /** @return array<int, array<string, string>> */
    public static function load(string $file): array
    {
        $fh = fopen($file, 'rb');
        if (false === $fh) {
            throw new \RuntimeException('cannot read ' . $file);
        }
        $head = fgetcsv($fh, null, ',', '"', '');
        $rows = [];
        while (is_array($head) && false !== ($line = fgetcsv($fh, null, ',', '"', ''))) {
            if (count($line) === count($head)) {
                $rows[] = array_combine($head, $line);
            }
        }
        fclose($fh);
        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, array<int, array<string, string>>> rows by scenario, active rows only unless $active is false.
     */
    public static function by_scenario(array $rows, bool $active = true): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (('1' === ($row['wpmcp_active'] ?? '1')) === $active) {
                $out[ $row['scenario'] ][] = $row;
            }
        }
        uksort($out, static fn ($a, $b) => (int) substr($a, 1) <=> (int) substr($b, 1));
        return $out;
    }

    /** @param array<int, array<string, string>> $rows */
    public static function stat(array $rows, string $metric, float $p): ?float
    {
        return percentile(array_column($rows, $metric), $p);
    }

    public static function fmt(?float $value): string
    {
        if (null === $value) {
            return '-';
        }
        if (abs($value) >= 1000) {
            return number_format($value, 0, '.', ',');
        }
        return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');
    }

    public static function change(?float $before, ?float $after): string
    {
        if (null === $before || null === $after) {
            return '-';
        }
        if (0.0 === $before) {
            return 0.0 === $after ? '0%' : 'new';
        }
        return sprintf('%+.0f%%', ($after - $before) / $before * 100);
    }

    /**
     * @param array<int, array<string, string>>      $before
     * @param array<int, array<string, string>>|null $after
     */
    public static function table(array $before, ?array $after): string
    {
        $b   = self::by_scenario($before);
        $a   = null === $after ? [] : self::by_scenario($after);
        $ids = array_unique(array_merge(array_keys($b), array_keys($a)));
        usort($ids, static fn ($x, $y) => (int) substr($x, 1) <=> (int) substr($y, 1));

        $out = [];
        foreach ($ids as $id) {
            $rows_b = $b[ $id ] ?? [];
            $rows_a = $a[ $id ] ?? [];
            $any    = $rows_b[0] ?? $rows_a[0];
            $out[]  = sprintf('### %s %s (n=%d%s, HTTP %s)', $id, Bench::scenario_name($id), count($rows_b), null === $after ? '' : ' / ' . count($rows_a), $any['http_status']);
            $out[]  = '';
            if (null === $after) {
                $out[] = '| metric | p50 | p95 |';
                $out[] = '|---|---:|---:|';
            } else {
                $out[] = '| metric | before p50 | after p50 | change p50 | before p95 | after p95 | change p95 |';
                $out[] = '|---|---:|---:|---:|---:|---:|---:|';
            }
            foreach (self::METRICS as $metric => $label) {
                $b50 = self::stat($rows_b, $metric, 50);
                $b95 = self::stat($rows_b, $metric, 95);
                if (null === $after) {
                    if (null !== $b50) {
                        $out[] = sprintf('| %s | %s | %s |', $label, self::fmt($b50), self::fmt($b95));
                    }
                    continue;
                }
                $a50 = self::stat($rows_a, $metric, 50);
                $a95 = self::stat($rows_a, $metric, 95);
                if (null !== $b50 || null !== $a50) {
                    $out[] = sprintf(
                        '| %s | %s | %s | %s | %s | %s | %s |',
                        $label,
                        self::fmt($b50),
                        self::fmt($a50),
                        self::change($b50, $a50),
                        self::fmt($b95),
                        self::fmt($a95),
                        self::change($b95, $a95)
                    );
                }
            }
            $out[] = '';
        }
        return implode("\n", $out);
    }

    /** @param array<int, array<string, string>> $rows */
    public static function delta(array $rows, string $title): string
    {
        $on  = self::by_scenario($rows, true);
        $off = self::by_scenario($rows, false);
        if ([] === $off) {
            return '';
        }
        $out   = ['### Delta, WP MCP active minus inactive (' . $title . ')', ''];
        $out[] = '| scenario | server ms p50 active | inactive | delta | wall ms delta p50 | peak memory delta p50 | files delta p50 |';
        $out[] = '|---|---:|---:|---:|---:|---:|---:|';
        foreach ($off as $id => $rows_off) {
            $rows_on = $on[ $id ] ?? [];
            $s_on    = self::stat($rows_on, 'server_ms', 50);
            $s_off   = self::stat($rows_off, 'server_ms', 50);
            $w       = self::diff(self::stat($rows_on, 'wall_ms', 50), self::stat($rows_off, 'wall_ms', 50));
            $m       = self::diff(self::stat($rows_on, 'peak_mem', 50), self::stat($rows_off, 'peak_mem', 50));
            $f       = self::diff(self::stat($rows_on, 'all_files', 50), self::stat($rows_off, 'all_files', 50));
            $out[]   = sprintf(
                '| %s %s | %s | %s | %s | %s | %s | %s |',
                $id,
                Bench::scenario_name($id),
                self::fmt($s_on),
                self::fmt($s_off),
                self::fmt(self::diff($s_on, $s_off)),
                self::fmt($w),
                null === $m ? '-' : sprintf('%.2f MB', $m / 1048576),
                self::fmt($f)
            );
        }
        $out[] = '';
        $out[] = 'Each delta is the difference of the two p50s, not the p50 of paired differences.';
        $out[] = '';
        return implode("\n", $out);
    }

    private static function diff(?float $a, ?float $b): ?float
    {
        return null === $a || null === $b ? null : $a - $b;
    }
}

$files = array_slice($argv, 1);
if ([] === $files || count($files) > 2) {
    fwrite(STDERR, "usage: php bin/bench/compare.php before.csv [after.csv]\n");
    exit(2);
}
$before = Compare::load($files[0]);
$after  = isset($files[1]) ? Compare::load($files[1]) : null;

$label_b = $before[0]['label'] ?? 'before';
$label_a = null === $after ? '' : ($after[0]['label'] ?? 'after');
$php     = $before[0]['php'] ?? '?';
$opcache = '1' === ($before[0]['opcache'] ?? '') ? 'on' : 'off';
echo null === $after
    ? sprintf("## Bench: %s (PHP %s, opcache %s)\n\n", $label_b, $php, $opcache)
    : sprintf("## Bench: %s vs %s (PHP %s, opcache %s)\n\n", $label_b, $label_a, $php, $opcache);
echo 'p50 and p95 are nearest-rank percentiles over the measured runs (warm-up runs are not recorded).', "\n\n";
echo Compare::table($before, $after), "\n";
echo Compare::delta($before, $label_b);
if (null !== $after) {
    echo Compare::delta($after, $label_a);
}
