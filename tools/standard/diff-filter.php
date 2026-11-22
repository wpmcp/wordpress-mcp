<?php

/**
 * The legacy baseline of `composer standard --diff`: keeps only the findings
 * on lines a change added or modified, so legacy code is held to the
 * standard exactly where it is touched. A new (or untracked) file keeps every
 * finding.
 *
 *   phpstan ... --error-format=json | php tools/standard/diff-filter.php phpstan <base>
 *   phpcs ... --report=json         | php tools/standard/diff-filter.php phpcs <base>
 *
 * Prints the kept findings as `path:line: message [code]` and exits 1 when
 * any is kept, 0 when none, 2 on bad input.
 */

declare(strict_types=1);

[$wpmcp_tool, $wpmcp_base] = [$argv[1] ?? '', $argv[2] ?? ''];
if (! in_array($wpmcp_tool, ['phpstan', 'phpcs'], true) || '' === $wpmcp_base) {
    fwrite(STDERR, "usage: php tools/standard/diff-filter.php phpstan|phpcs <base-sha>\n");
    exit(2);
}
$wpmcp_root = (string) realpath(dirname(__DIR__, 2));
$wpmcp_report = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($wpmcp_report)) {
    fwrite(STDERR, "diff-filter: {$wpmcp_tool} did not print a JSON report\n");
    exit(2);
}

/** @return array<int, true>|null changed line numbers, null when the whole file is new */
function wpmcp_changed(string $base, string $relative): ?array
{
    $out = [];
    exec('git cat-file -e ' . escapeshellarg($base . ':' . $relative) . ' 2>/dev/null', $out, $code);
    if (0 !== $code) {
        return null;
    }
    $lines = [];
    $diff = [];
    exec('git diff -U0 --no-color ' . escapeshellarg($base) . ' -- ' . escapeshellarg($relative), $diff);
    foreach ($diff as $row) {
        if (1 !== preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $row, $m)) {
            continue;
        }
        $start = (int) $m[1];
        $count = isset($m[2]) ? (int) $m[2] : 1;
        for ($i = $start; $i < $start + $count; $i++) {
            $lines[ $i ] = true;
        }
    }
    return $lines;
}

/**
 * @param array<mixed> $report
 * @return list<array{string, int, string}> file, line, message
 */
function wpmcp_findings(array $report, string $code_key): array
{
    $findings = [];
    $files = is_array($report['files'] ?? null) ? $report['files'] : [];
    foreach ($files as $file => $row) {
        $messages = is_array($row) && is_array($row['messages'] ?? null) ? $row['messages'] : [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $line = is_int($message['line'] ?? null) ? $message['line'] : 0;
            $text = is_string($message['message'] ?? null) ? $message['message'] : '';
            $code = is_string($message[ $code_key ] ?? null) ? $message[ $code_key ] : '';
            $findings[] = [(string) $file, $line, "{$text} [{$code}]"];
        }
    }
    $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
    foreach ($errors as $error) {
        if (is_string($error)) {
            $findings[] = ['', 0, $error];
        }
    }
    return $findings;
}

$findings = wpmcp_findings($wpmcp_report, 'phpstan' === $wpmcp_tool ? 'identifier' : 'source');

$kept = [];
$cache = [];
foreach ($findings as [$file, $line, $message]) {
    if ('' === $file) {
        $kept[] = $message;
        continue;
    }
    $real = (string) realpath($file);
    $relative = str_starts_with($real, $wpmcp_root . '/') ? substr($real, strlen($wpmcp_root) + 1) : $file;
    $cache[ $relative ] ??= ['lines' => wpmcp_changed($wpmcp_base, $relative)];
    $changed = $cache[ $relative ]['lines'];
    if (null === $changed || isset($changed[ $line ])) {
        $kept[] = "{$relative}:{$line}: {$message}";
    }
}

if ([] !== $kept) {
    echo implode("\n", $kept), "\n";
    exit(1);
}
exit(0);
