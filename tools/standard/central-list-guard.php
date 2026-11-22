<?php

/**
 * Central-list guard (docs/CODING-STANDARD.md rule 12, "How to add an
 * integration"). New integrations self-register as modules; the compiled
 * registry feeds the central lists (D11).
 *
 * - SEAMED files (the lists that read the registry, and the flavor strip):
 *   a change may add no literal entry at all. In the lists an entry is a
 *   line such as `'key' => [`, `'key' => __(`, `'key' => 'value'`, a lone
 *   `'value',` or `Some_Pack::operations(),`; in strip.php and policy.php
 *   every added code line counts.
 * - CENTRAL files without a seam yet: the file may grow by at most one line
 *   (blank and comment lines aside), the registration line.
 *
 *   php tools/standard/central-list-guard.php <base-ref>
 *
 * Measured against the merge base of <base-ref> and HEAD, working tree
 * included: for every file in CENTRAL, added lines minus removed lines
 * (blank, comment-only and brace-only lines ignored) must be 1 or less. A line carrying
 * `central-list-ok: <reason>` is not counted (the allowance sits next to the
 * code, rule S10). For readme.txt and README.md, an added line that states a
 * hand-maintained count ("274 abilities") fails: counts come from generated
 * docs. Exit codes: 0 ok, 1 guard failed, 2 bad usage or git failure.
 */

declare(strict_types=1);

const SEAMED = [
    'src/Governance/Opt_In_Settings.php' => 'entries',
    'src/Integrations/Plugin_Refusal.php' => 'entries',
    'src/Integrations/Theme_Integration.php' => 'entries',
    'src/Data_Registry.php' => 'entries',
    'scripts/flavors/wporg/strip.php' => 'lines',
    'scripts/flavors/wporg/policy.php' => 'lines',
];
const CENTRAL = [
    'src/Integrations/Plugin_Data_Integration.php',
    'src/Plugin.php',
];
const ENTRY_PATTERN = <<<'RE'
/^\s*(?:(['"])[^'"]+\1\s*=>\s*(?:\[|__\(|(?:'[^']*'|"[^"]*"|-?\d+|true|false)\s*,?\s*$)|(['"])[^'"]*\2\s*,\s*$|[A-Za-z_\\]+::operations\(\))/
RE;
const COUNTED_DOCS = ['readme.txt', 'README.md'];
const COUNT_PATTERN = '/\b\d{2,}\+?\s+(abilities|tools|integrations|plugins|packs)\b/i';

/** @return list<string> */
function wpmcp_git(string $args): array
{
    $out = [];
    $code = 0;
    exec('git ' . $args . ' 2>/dev/null', $out, $code);
    if (0 !== $code) {
        fwrite(STDERR, "central-list guard: git {$args} failed\n");
        exit(2);
    }
    return $out;
}

/** @return array{list<string>, list<string>} added and removed lines */
function wpmcp_changed_lines(string $base, string $file): array
{
    $added = [];
    $removed = [];
    foreach (wpmcp_git('diff -U0 --no-color ' . escapeshellarg($base) . ' -- ' . escapeshellarg($file)) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }
        if (str_starts_with($line, '+')) {
            $added[] = substr($line, 1);
        } elseif (str_starts_with($line, '-')) {
            $removed[] = substr($line, 1);
        }
    }
    return [$added, $removed];
}

function wpmcp_counts(string $line): bool
{
    $code = trim($line);
    // Blank lines, allowances and lines of only braces or brackets carry no entry.
    if ('' === $code || str_contains($code, 'central-list-ok:') || 1 === preg_match('/^[{}()\[\];,]+$/', $code)) {
        return false;
    }
    return ! (str_starts_with($code, '//') || str_starts_with($code, '#') || str_starts_with($code, '*') || str_starts_with($code, '/*'));
}

$ref = $argv[1] ?? '';
if ('' === $ref) {
    fwrite(STDERR, "usage: php tools/standard/central-list-guard.php <base-ref>\n");
    exit(2);
}
$base = wpmcp_git('merge-base ' . escapeshellarg($ref) . ' HEAD')[0] ?? '';

$failures = [];
foreach (SEAMED as $file => $mode) {
    [$added, $removed] = wpmcp_changed_lines($base, $file);
    $counted = array_filter($added, 'wpmcp_counts');
    if ('entries' === $mode) {
        $counted = array_filter($counted, static fn (string $line): bool => 1 === preg_match(ENTRY_PATTERN, $line));
        $growth = count($counted);
    } else {
        $growth = count($counted) - count(array_filter($removed, 'wpmcp_counts'));
    }
    if ($growth > 0) {
        $failures[] = sprintf('%s gains %d literal %s. Declare it on the module instead (docs/CODING-STANDARD.md, How to add an integration), or mark a justified line with `central-list-ok: <reason>`.', $file, $growth, 'entries' === $mode ? 'entry line(s)' : 'line(s)');
    }
}
foreach (CENTRAL as $file) {
    [$added, $removed] = wpmcp_changed_lines($base, $file);
    $growth = count(array_filter($added, 'wpmcp_counts')) - count(array_filter($removed, 'wpmcp_counts'));
    if ($growth > 1) {
        $failures[] = sprintf('%s grows by %d lines (at most 1, the registration line). Self-register in the module directory instead, or mark a justified line with `central-list-ok: <reason>`.', $file, $growth);
    }
}
foreach (COUNTED_DOCS as $file) {
    foreach (wpmcp_changed_lines($base, $file)[0] as $line) {
        if (1 === preg_match(COUNT_PATTERN, $line) && ! str_contains($line, 'central-list-ok:')) {
            $failures[] = sprintf('%s adds a hand-maintained count: "%s". Counts come from generated docs.', $file, trim($line));
        }
    }
}

if ([] !== $failures) {
    fwrite(STDERR, "central-list guard failed (docs/CODING-STANDARD.md rule 12):\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "central-list guard: ok\n";
