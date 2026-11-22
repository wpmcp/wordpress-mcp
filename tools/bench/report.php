<?php

/**
 * Prints the code mode benchmark table from a results JSON (issue #1130).
 *
 *   php tools/bench/report.php tools/bench/baseline-2026-11-20.json [--markdown]
 *
 * Tokens are bytes divided by the factor recorded in the file (bytes / 4
 * until calibrate-tokens.php has measured one). "total" is tools/list plus
 * every result: what the scenario put in front of the model.
 *
 * REPO-ONLY: plain PHP, no WordPress load.
 */

declare(strict_types=1);

use WPMCP\Bench\Data;

require_once __DIR__ . '/Data.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args     = Data::strs($_SERVER, 'argv');
$file     = $args[1] ?? '';
$markdown = in_array('--markdown', $args, true);
$doc      = '' !== $file && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
if (! is_array($doc)) {
    fwrite(STDERR, "usage: php tools/bench/report.php RESULTS.json [--markdown]\n");
    exit(2);
}

$factor = Data::float($doc, 'tokens', 'bytes_per_token');
$factor = $factor > 0 ? $factor : 4.0;
$tok    = static fn(int $bytes): string => number_format((int) round($bytes / $factor));
$num    = static fn(int $n): string => number_format($n);
$or     = static fn(string $value, string $fallback): string => '' === $value ? $fallback : $value;

$head = ['#', 'scenario', 'surface', 'tools/list B', '~tok', 'calls', 'turns', 'result B', '~tok', 'total ~tok', 'ms', 'status'];
$rows = [];
foreach (Data::rows($doc, 'scenarios') as $scenario) {
    foreach (['full', 'compact'] as $surface) {
        $r    = Data::at($scenario, 'surfaces', $surface);
        $list = Data::int($doc, 'tools_list', $surface, 'bytes');
        if (! is_array($r)) {
            continue;
        }
        $id   = Data::str($scenario, 'id');
        $slug = Data::str($scenario, 'slug');
        if ('skipped' === Data::str($r, 'status')) {
            $rows[] = [$id, $slug, $surface, $num($list), $tok($list), '-', '-', '-', '-', '-', '-', 'skipped: ' . Data::str($r, 'reason')];
            continue;
        }
        $result = Data::int($r, 'result_bytes');
        $status = 'ok' === Data::str($r, 'status') ? 'ok' : 'FAILED: ' . Data::str($r, 'reason');
        $rows[] = [
            $id,
            $slug,
            $surface,
            $num($list),
            $tok($list),
            (string) Data::int($r, 'calls'),
            (string) Data::int($r, 'turns'),
            $num($result),
            $tok($result),
            $tok($list + $result),
            (string) round(Data::float($r, 'ms')),
            $status,
        ];
    }
}

$commit = Data::str($doc, 'commit');
printf(
    "%s mode, %s, WordPress %s, wpmcp %s%s\n",
    $or(Data::str($doc, 'mode'), '?'),
    $or(Data::str($doc, 'generated_at'), '?'),
    $or(Data::str($doc, 'wordpress'), '?'),
    $or(Data::str($doc, 'wpmcp'), '?'),
    '' !== $commit ? ' at ' . $commit : ''
);
foreach (Data::map($doc, 'tools_list') as $surface => $list) {
    printf("tools/list %s: %s tools, %s bytes, %s\n", $surface, $num(Data::int($list, 'tools')), $num(Data::int($list, 'bytes')), Data::str($list, 'source'));
}
printf("tokens: %s\n\n", $or(Data::str($doc, 'tokens', 'method'), 'bytes / 4'));

if ($markdown) {
    echo '| ' . implode(' | ', $head) . " |\n";
    echo '|' . str_repeat(' --- |', count($head)) . "\n";
    foreach ($rows as $row) {
        echo '| ' . implode(' | ', array_map(static fn(string $c): string => str_replace('|', '\\|', $c), $row)) . " |\n";
    }
    exit(0);
}

$widths = array_map('strlen', $head);
$last   = count($head) - 1;
foreach ($rows as $row) {
    foreach ($row as $i => $cell) {
        if ($i < $last) {
            $widths[ $i ] = max($widths[ $i ] ?? 0, strlen($cell));
        }
    }
}
$line = static function (array $cells) use ($widths): string {
    $out  = [];
    $last = count($cells) - 1;
    foreach (array_values($cells) as $i => $cell) {
        $text  = is_string($cell) ? $cell : '';
        $out[] = $i === $last ? $text : str_pad($text, $widths[ $i ] ?? 0, ' ', $i >= 3 ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }
    return implode('  ', $out) . "\n";
};
echo $line($head);
foreach ($rows as $row) {
    echo $line($row);
}
