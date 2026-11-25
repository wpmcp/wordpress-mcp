#!/usr/bin/env php
<?php

/**
 * Renames the product from the spaced two-word "WP MCP" to the one-word
 * "WPMCP" (owner decision, 24 Nov 2026, for the 2.0 release), in this
 * checkout and in the pro/ submodule when it is checked out.
 *
 *   php bin/brand-wpmcp.php            # rewrite, then regenerate languages/wpmcp.pot
 *   php bin/brand-wpmcp.php --check    # change nothing; list what would change, exit 1 if anything would
 *   php bin/brand-wpmcp.php --no-pro   # leave the pro/ submodule alone
 *   php bin/brand-wpmcp.php --no-pot   # skip the .pot step
 *   php bin/brand-wpmcp.php --pot      # regenerate the .pot even when it no longer carries the old name
 *
 * Safe to run any number of times: the only edit turns the old name into
 * one that cannot match again, so a second run finds nothing and writes
 * nothing, and the .pot is regenerated only while it still carries the old
 * name. Run it again after merging other branches to catch what they add.
 *
 * What it rewrites: the exact, case-sensitive "WP MCP" standing as its own
 * word ("WP MCP's", "WP MCP Pro", "WP MCP Cloud", "WP MCP:"), in every
 * git-tracked text file: plugin headers, readmes, admin, notice and refusal
 * strings, tool, ability and operation descriptions, docs, the README,
 * tests and comments. A comment line that wraps between the two words is
 * joined. "WPMCP Pro" follows from "WP MCP Pro".
 *
 * What it never touches:
 *  - code identifiers. The match is case-sensitive and must stand alone, so
 *    wpmcp, WPMCP_*, WP_MCP_VERSION, wp_mcp, wp-mcp, the text domain, slugs,
 *    option, hook, class and function names, file paths and URLs (none of
 *    which can hold a space) stay as they are, and so do "WordPress MCP",
 *    "MCP server" and the WP-CLI command "wp mcp-stdio";
 *  - the historical changelog: in every readme.txt, the Changelog and
 *    Upgrade Notice entries of released versions (a v<version> git tag
 *    exists, or the version is older than the Version header of wpmcp.php),
 *    which describe what shipped. tests/free/Lint/BrandNameGuardTest.php
 *    applies the same rule;
 *  - the paths and the "keep:" lines in bin/brand-wpmcp.exclude: generated
 *    files, dated design records, and strings the code compares, hashes or
 *    persists;
 *  - binary files, this script and its exclusion file.
 *
 * After a rewrite, regenerate the golden baseline (rule 13 of
 * docs/CODING-STANDARD.md) and run the gates; the script prints the
 * commands. Commit pro/ on its own branch before the submodule pointer.
 *
 * Exit status: 0 when done (with --check: when nothing is left to change),
 * 1 with --check when something would change, or when the .pot needs WP-CLI
 * and none is found (set WPMCP_WP_CLI to its path), 2 on bad usage.
 */

declare(strict_types=1);

// Spelled in two parts so that this file never matches itself.
const WPMCP_BRAND_OLD = 'WP' . ' MCP';
const WPMCP_BRAND_NEW = 'WPMCP';

// The old name standing alone: not glued to a letter, digit or underscore.
const WPMCP_BRAND_PATTERN = '/(?<![A-Za-z0-9_])WP MCP(?![A-Za-z0-9_])/';

// The old name wrapped across two comment lines ("every WP" / " * MCP admin").
// The word glued after MCP moves up with it and the next line keeps its
// indent: "every WPMCP" / " * admin".
const WPMCP_BRAND_WRAPPED = '/(?<![A-Za-z0-9_])WP(\R[ \t]*(?:\*|\/\/|#)?[ \t]*)MCP(?![A-Za-z0-9_])(\S*)[ \t]+/';

const WPMCP_BRAND_SELF = ['bin/brand-wpmcp.php', 'bin/brand-wpmcp.exclude'];

const WPMCP_BRAND_POT = 'languages/wpmcp.pot';

/**
 * @param list<string> $args
 * @return array{check: bool, pro: bool, pot: bool|null}|null null on bad usage
 */
function wpmcp_brand_options(array $args): ?array
{
    $options = ['check' => false, 'pro' => true, 'pot' => null];
    foreach ($args as $arg) {
        if ('--check' === $arg) {
            $options['check'] = true;
        } elseif ('--no-pro' === $arg) {
            $options['pro'] = false;
        } elseif ('--no-pot' === $arg) {
            $options['pot'] = false;
        } elseif ('--pot' === $arg) {
            $options['pot'] = true;
        } else {
            return null;
        }
    }

    return $options;
}

/**
 * The tracked files of the repository at $repo, as paths relative to the
 * outer repository root.
 *
 * @return list<string>
 */
function wpmcp_brand_tracked(string $repo, string $prefix): array
{
    $listing = shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z 2>/dev/null');
    if (! is_string($listing) || '' === $listing) {
        return [];
    }
    $paths = [];
    foreach (explode("\0", $listing) as $path) {
        if ('' !== $path) {
            $paths[] = $prefix . $path;
        }
    }

    return $paths;
}

/**
 * @return array{paths: list<string>, keep: list<string>}|null null when the file is missing
 */
function wpmcp_brand_exclusions(string $file): ?array
{
    $rows = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
    if (false === $rows) {
        return null;
    }
    $out = ['paths' => [], 'keep' => []];
    foreach ($rows as $row) {
        $row = trim($row);
        if ('' === $row || str_starts_with($row, '#')) {
            continue;
        }
        if (str_starts_with($row, 'keep:')) {
            $text = trim(substr($row, 5));
            if ('' !== $text) {
                $out['keep'][] = $text;
            }
            continue;
        }
        $out['paths'][] = wpmcp_brand_glob_regex($row);
    }

    return $out;
}

/** A path glob as a regex: * within one directory, ** across them, a trailing / for a whole tree. */
function wpmcp_brand_glob_regex(string $glob): string
{
    $tree = str_ends_with($glob, '/');
    $glob = trim($glob, '/');
    $regex = '';
    $length = strlen($glob);
    for ($i = 0; $i < $length; $i++) {
        $char = $glob[$i];
        if ('*' === $char && '*' === ($glob[$i + 1] ?? '')) {
            $regex .= '.*';
            $i++;
        } elseif ('*' === $char) {
            $regex .= '[^/]*';
        } elseif ('?' === $char) {
            $regex .= '[^/]';
        } else {
            $regex .= preg_quote($char, '~');
        }
    }

    return '~^' . $regex . ($tree ? '(?:/.*)?' : '') . '$~';
}

/** @param list<string> $patterns */
function wpmcp_brand_excluded(string $path, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (1 === preg_match($pattern, $path)) {
            return true;
        }
    }

    return false;
}

/**
 * Versions that have a release tag (v1.2.1 or 1.2.1).
 *
 * @return array<string, true>
 */
function wpmcp_brand_tagged_versions(string $repo): array
{
    $tags = shell_exec('git -C ' . escapeshellarg($repo) . ' tag -l 2>/dev/null');
    $out = [];
    foreach (explode("\n", is_string($tags) ? $tags : '') as $tag) {
        $tag = trim($tag);
        if (1 === preg_match('/^v?(\d+(?:\.\d+)*)$/', $tag, $m)) {
            $out[$m[1]] = true;
        }
    }

    return $out;
}

/** The Version header of the main plugin file, or null. */
function wpmcp_brand_header_version(string $root): ?string
{
    $head = is_file($root . '/wpmcp.php') ? (string) file_get_contents($root . '/wpmcp.php', false, null, 0, 8192) : '';

    return 1 === preg_match('/^[ \t\/*#@]*Version:\s*(\d+(?:\.\d+)*)\s*$/mi', $head, $m) ? $m[1] : null;
}

/** @param array<string, true> $tags */
function wpmcp_brand_released(string $version, array $tags, ?string $header): bool
{
    if (1 !== preg_match('/^\d+(?:\.\d+)*$/', $version)) {
        return false;
    }

    return isset($tags[$version]) || (null !== $header && version_compare($version, $header, '<'));
}

/**
 * The lines of a readme.txt that belong to the Changelog or Upgrade Notice
 * entry of a released version (the "= x.y.z =" line included).
 *
 * @param list<string> $lines
 * @param callable(string): bool $released
 * @return array<int, true> 0-based line index => true
 */
function wpmcp_brand_released_lines(array $lines, callable $released): array
{
    $out = [];
    $section = '';
    $inside = false;
    foreach ($lines as $index => $line) {
        // "== Changelog ==" starts a section; "=== Title ===" does not.
        if (1 === preg_match('/^==\s*([^=].*?)\s*==\s*$/', $line, $m)) {
            $section = strtolower($m[1]);
            $inside = false;
            continue;
        }
        if ('changelog' !== $section && 'upgrade notice' !== $section) {
            continue;
        }
        if (1 === preg_match('/^=\s*([^=]+?)\s*=\s*$/', $line, $m)) {
            $inside = $released(trim($m[1]));
        }
        if ($inside) {
            $out[$index] = true;
        }
    }

    return $out;
}

/**
 * @param list<string> $lines
 * @param list<string> $keep
 * @return array<int, true>
 */
function wpmcp_brand_kept_lines(array $lines, array $keep): array
{
    $out = [];
    foreach ($lines as $index => $line) {
        foreach ($keep as $text) {
            if (str_contains($line, $text)) {
                $out[$index] = true;
                break;
            }
        }
    }

    return $out;
}

/**
 * Rewrites one file. Lines in $protected are left exactly as they are.
 *
 * @param array<int, true> $protected 0-based line index => true
 * @return array{0: string, 1: int, 2: int} new contents, replacements made, occurrences left alone
 */
function wpmcp_brand_rewrite(string $contents, array $protected): array
{
    $made = 0;
    $left = 0;

    // Wrapped occurrences first. The line break stays, so line numbers hold.
    if (preg_match_all(WPMCP_BRAND_WRAPPED, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        $out = '';
        $from = 0;
        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $line = substr_count($contents, "\n", 0, $offset);
            if (isset($protected[$line]) || isset($protected[$line + 1])) {
                $left++;
                continue;
            }
            $out .= substr($contents, $from, $offset - $from) . WPMCP_BRAND_NEW . $match[2][0] . $match[1][0];
            $from = $offset + strlen($whole);
            $made++;
        }
        $contents = $out . substr($contents, $from);
    }

    $lines = explode("\n", $contents);
    foreach ($lines as $index => $line) {
        if (! str_contains($line, WPMCP_BRAND_OLD)) {
            continue;
        }
        if (isset($protected[$index])) {
            $left += preg_match_all(WPMCP_BRAND_PATTERN, $line);
            continue;
        }
        $lines[$index] = (string) preg_replace(WPMCP_BRAND_PATTERN, WPMCP_BRAND_NEW, $line, -1, $count);
        $made += $count;
    }

    return [implode("\n", $lines), $made, $left];
}

function wpmcp_brand_say(string $line): void
{
    fwrite(STDERR, '[brand] ' . $line . "\n");
}

/** The WP-CLI to run make-pot with: WPMCP_WP_CLI, else wp on the PATH, else null. */
function wpmcp_brand_wp_cli(): ?string
{
    $env = getenv('WPMCP_WP_CLI');
    if (is_string($env) && '' !== $env) {
        return $env;
    }
    $found = trim((string) shell_exec('command -v wp 2>/dev/null'));

    return '' === $found ? null : $found;
}

$wpmcp_brand_root = dirname(__DIR__);
$wpmcp_brand_options = wpmcp_brand_options(array_values(array_slice($argv, 1)));
if (null === $wpmcp_brand_options) {
    fwrite(STDERR, "usage: php bin/brand-wpmcp.php [--check] [--no-pro] [--no-pot | --pot]\n");
    exit(2);
}
$wpmcp_brand_exclude = wpmcp_brand_exclusions($wpmcp_brand_root . '/bin/brand-wpmcp.exclude');
if (null === $wpmcp_brand_exclude) {
    fwrite(STDERR, "brand: bin/brand-wpmcp.exclude is missing\n");
    exit(2);
}
$wpmcp_brand_check = $wpmcp_brand_options['check'];

$wpmcp_brand_tags = wpmcp_brand_tagged_versions($wpmcp_brand_root);
$wpmcp_brand_header = wpmcp_brand_header_version($wpmcp_brand_root);
$wpmcp_brand_is_released = static function (string $version) use ($wpmcp_brand_tags, $wpmcp_brand_header): bool {
    return wpmcp_brand_released($version, $wpmcp_brand_tags, $wpmcp_brand_header);
};

$wpmcp_brand_files = wpmcp_brand_tracked($wpmcp_brand_root, '');
$wpmcp_brand_pro = $wpmcp_brand_root . '/pro';
if (! $wpmcp_brand_options['pro']) {
    $wpmcp_brand_pro_out = 'skipped (--no-pro)';
} elseif (file_exists($wpmcp_brand_pro . '/.git')) {
    $wpmcp_brand_files = array_merge($wpmcp_brand_files, wpmcp_brand_tracked($wpmcp_brand_pro, 'pro/'));
    $wpmcp_brand_pro_out = 'included';
} else {
    $wpmcp_brand_pro_out = 'not checked out, skipped';
}
if ([] === $wpmcp_brand_files) {
    fwrite(STDERR, "brand: git ls-files listed nothing; run this from a git checkout\n");
    exit(2);
}

$wpmcp_brand_changed = [];
$wpmcp_brand_left = [];
foreach ($wpmcp_brand_files as $wpmcp_brand_path) {
    $wpmcp_brand_full = $wpmcp_brand_root . '/' . $wpmcp_brand_path;
    if (in_array($wpmcp_brand_path, WPMCP_BRAND_SELF, true) || ! is_file($wpmcp_brand_full) || is_link($wpmcp_brand_full)) {
        continue;
    }
    $wpmcp_brand_contents = (string) file_get_contents($wpmcp_brand_full);
    if (! str_contains($wpmcp_brand_contents, 'MCP') || str_contains(substr($wpmcp_brand_contents, 0, 8000), "\0")) {
        continue;
    }
    if (wpmcp_brand_excluded($wpmcp_brand_path, $wpmcp_brand_exclude['paths'])) {
        $wpmcp_brand_count = preg_match_all(WPMCP_BRAND_PATTERN, $wpmcp_brand_contents);
        if ($wpmcp_brand_count > 0) {
            $wpmcp_brand_left[$wpmcp_brand_path] = [$wpmcp_brand_count, 'excluded path'];
        }
        continue;
    }

    $wpmcp_brand_lines = explode("\n", $wpmcp_brand_contents);
    $wpmcp_brand_kept_by = wpmcp_brand_kept_lines($wpmcp_brand_lines, $wpmcp_brand_exclude['keep']);
    $wpmcp_brand_released_by = 'readme.txt' === strtolower(basename($wpmcp_brand_path))
        ? wpmcp_brand_released_lines($wpmcp_brand_lines, $wpmcp_brand_is_released)
        : [];

    [$wpmcp_brand_new, $wpmcp_brand_made, $wpmcp_brand_kept] = wpmcp_brand_rewrite(
        $wpmcp_brand_contents,
        $wpmcp_brand_kept_by + $wpmcp_brand_released_by
    );
    if ($wpmcp_brand_kept > 0) {
        $wpmcp_brand_left[$wpmcp_brand_path] = [
            $wpmcp_brand_kept,
            [] === $wpmcp_brand_released_by ? 'keep: line' : 'released changelog entries',
        ];
    }
    if (0 === $wpmcp_brand_made) {
        continue;
    }
    $wpmcp_brand_changed[$wpmcp_brand_path] = $wpmcp_brand_made;
    if (! $wpmcp_brand_check && false === file_put_contents($wpmcp_brand_full, $wpmcp_brand_new)) {
        wpmcp_brand_say('could not write ' . $wpmcp_brand_path);
        exit(1);
    }
}

$wpmcp_brand_verb = $wpmcp_brand_check ? 'would replace' : 'replaced';
foreach ($wpmcp_brand_changed as $wpmcp_brand_path => $wpmcp_brand_count) {
    fwrite(STDOUT, sprintf("%5d  %s\n", $wpmcp_brand_count, $wpmcp_brand_path));
}
wpmcp_brand_say(sprintf(
    '%s %d occurrence(s) in %d file(s); pro/ %s; released versions: %d tagged, Version header %s',
    $wpmcp_brand_verb,
    array_sum($wpmcp_brand_changed),
    count($wpmcp_brand_changed),
    $wpmcp_brand_pro_out,
    count($wpmcp_brand_tags),
    $wpmcp_brand_header ?? 'unknown'
));
foreach ($wpmcp_brand_left as $wpmcp_brand_path => [$wpmcp_brand_count, $wpmcp_brand_why]) {
    wpmcp_brand_say(sprintf('left alone: %d in %s (%s)', $wpmcp_brand_count, $wpmcp_brand_path, $wpmcp_brand_why));
}

// The .pot is regenerated from src/, never rewritten: make-pot also picks up
// every other string the tree changed since the last run.
$wpmcp_brand_pending = [] !== $wpmcp_brand_changed;
$wpmcp_brand_pot = $wpmcp_brand_root . '/' . WPMCP_BRAND_POT;
$wpmcp_brand_pot_stale = is_file($wpmcp_brand_pot)
    && 1 === preg_match(WPMCP_BRAND_PATTERN, (string) file_get_contents($wpmcp_brand_pot));
if (false !== $wpmcp_brand_options['pot'] && is_file($wpmcp_brand_pot) && ($wpmcp_brand_pot_stale || true === $wpmcp_brand_options['pot'])) {
    $wpmcp_brand_make_pot = 'i18n make-pot src ' . WPMCP_BRAND_POT . ' --domain=wpmcp --package-name=' . WPMCP_BRAND_NEW;
    if ($wpmcp_brand_check) {
        wpmcp_brand_say('would regenerate ' . WPMCP_BRAND_POT . ' (wp ' . $wpmcp_brand_make_pot . ')');
        $wpmcp_brand_pending = true;
    } else {
        $wpmcp_brand_wp = wpmcp_brand_wp_cli();
        if (null === $wpmcp_brand_wp) {
            wpmcp_brand_say('WP-CLI not found (set WPMCP_WP_CLI); regenerate the .pot by hand: wp ' . $wpmcp_brand_make_pot);
            exit(1);
        }
        $wpmcp_brand_command = 'cd ' . escapeshellarg($wpmcp_brand_root) . ' && ' . escapeshellarg($wpmcp_brand_wp) . ' ' . $wpmcp_brand_make_pot . ' 2>&1';
        exec($wpmcp_brand_command, $wpmcp_brand_output, $wpmcp_brand_status);
        if (0 !== $wpmcp_brand_status) {
            fwrite(STDERR, implode("\n", $wpmcp_brand_output) . "\n");
            wpmcp_brand_say('make-pot failed (exit ' . $wpmcp_brand_status . ')');
            exit(1);
        }
        $wpmcp_brand_still = preg_match_all(WPMCP_BRAND_PATTERN, (string) file_get_contents($wpmcp_brand_pot));
        wpmcp_brand_say('regenerated ' . WPMCP_BRAND_POT . ($wpmcp_brand_still > 0 ? sprintf(', which still carries the old name %d time(s): a msgid in an excluded file or keep: line', $wpmcp_brand_still) : ''));
    }
} elseif (false !== $wpmcp_brand_options['pot'] && is_file($wpmcp_brand_pot)) {
    wpmcp_brand_say(WPMCP_BRAND_POT . ' carries no old name; not regenerated (--pot forces it)');
}

if ($wpmcp_brand_check) {
    wpmcp_brand_say($wpmcp_brand_pending ? 'changes pending' : 'nothing to change');
    exit($wpmcp_brand_pending ? 1 : 0);
}
if ([] !== $wpmcp_brand_changed) {
    wpmcp_brand_say('next: WPMCP_GOLDEN_REGENERATE=1 bash bin/test-local.sh -- --group golden tests/golden (rule 13; the baseline diff must be the rename and nothing else)');
    wpmcp_brand_say('then: bash bin/standard.sh --diff=origin/main, composer lint, composer check:flavors, the guard set and the fast lane');
    wpmcp_brand_say('pro/ changed? Commit it on its own branch first, then the submodule pointer here.');
}
exit(0);
