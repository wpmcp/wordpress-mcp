<?php

/**
 * The Pro split tool (dev only, never shipped). Moves the Pro-only code of
 * the public tree into pro/, the private submodule, and verifies that what
 * stays public names no Pro class.
 *
 *   php bin/pro-split.php check  [--root=DIR] [--strict]   verify pro-manifest.txt against the code
 *   php bin/pro-split.php plan   [--root=DIR]              print every move and write, change nothing
 *   php bin/pro-split.php move   [--root=DIR] [--dry-run]  apply the split (idempotent)
 *   php bin/pro-split.php suggest --seeds=FILE [--root=DIR] print a manifest computed from seed paths
 *
 * What a split does, in order:
 *
 *  1. Plugin.php: every Pro registration method, Pro ability row and Pro
 *     boot span listed in PLUGIN_SLOTS below is cut out of src/Plugin.php
 *     into pro/src/Pro/Plugin_Slots.php, and each call site becomes one
 *     Edition slot call ($this->edition()->run('<slot>', ...)). The order
 *     abilities register in does not change. The free build runs the slot
 *     calls as no-ops; src/Pro/bootstrap.php (moved with Pro) registers Plugin_Slots through the
 *     wpmcp_edition_slots filter (src/Edition/Edition.php).
 *  2. Every src/ path the manifest lists moves to pro/<path>, so namespaces
 *     stay as they are (composer maps WPMCP\ onto src/ and pro/src/).
 *  3. tests/pro/ moves to pro/tests/pro/ (tests/pro/Freemius stays public,
 *     under tests/free/Freemius), and every tests/free file that names a Pro
 *     class moves to pro/tests/free/.
 *
 * Re-running on a tree that is already split changes nothing. A path that
 * exists both at its public location and under pro/ is a conflict and stops
 * the run before anything is written.
 *
 * Exit codes: 0 ok, 1 check failed or conflict, 2 bad usage.
 */

declare(strict_types=1);

const MANIFEST = 'pro-manifest.txt';
const PRO_DIR = 'pro';
const SLOTS_FILE = 'pro/src/Pro/Plugin_Slots.php';
const SLOTS_CLASS = 'Plugin_Slots';
const SLOT_MARKER = '$this->edition()->run(';

/**
 * Plugin.php pieces that move into the Pro slot class. What displays content a
 * Pro tool created (the custom widget and block runtime, stored custom CSS,
 * dynamic templates, the chat conversation cleanup) stays public, so a site
 * that drops back to the free build keeps rendering it; only creating and
 * editing are Pro.
 *
 *  - method: a whole private or public method, moved by name.
 *  - ability: one `$registrar->register(new Ability('<name>', ...));`
 *    statement, plus the `$var = new X();` line that builds its handler when
 *    nothing else uses that variable.
 *  - span: the lines from the one line starting with `start` through the
 *    first line equal to `end` after it.
 *
 * @var list<array{kind: string, name: string, slot?: string, start?: string, end?: string}>
 */
const PLUGIN_SLOTS = [
    ['kind' => 'method', 'name' => 'register_analysis_abilities'],
    ['kind' => 'method', 'name' => 'register_atomic_elementor_abilities'],
    ['kind' => 'method', 'name' => 'register_brand_kit_abilities'],
    ['kind' => 'method', 'name' => 'register_builder_abilities'],
    ['kind' => 'method', 'name' => 'register_cli_abilities'],
    ['kind' => 'method', 'name' => 'register_cli_job_abilities'],
    ['kind' => 'method', 'name' => 'register_custom_code_abilities'],
    ['kind' => 'method', 'name' => 'register_elementor_pro_abilities'],
    ['kind' => 'method', 'name' => 'register_elementor_structural_abilities'],
    ['kind' => 'method', 'name' => 'register_global_class_write_abilities'],
    ['kind' => 'method', 'name' => 'register_global_variable_abilities'],
    ['kind' => 'method', 'name' => 'register_php_exec_abilities'],
    ['kind' => 'method', 'name' => 'register_seo_pro_abilities'],
    ['kind' => 'method', 'name' => 'register_cloud_abilities'],
    ['kind' => 'method', 'name' => 'register_block_builder_abilities'],
    ['kind' => 'method', 'name' => 'register_widget_builder_abilities'],
    ['kind' => 'method', 'name' => 'register_memory_abilities'],
    ['kind' => 'method', 'name' => 'register_forms_pack_abilities'],
    ['kind' => 'method', 'name' => 'register_block_suite_abilities'],
    ['kind' => 'method', 'name' => 'register_image_optimization_runtime_hooks'],
    ['kind' => 'ability', 'name' => 'wpmcp/optimize-media', 'slot' => 'abilities.media.optimize'],
    ['kind' => 'ability', 'name' => 'wpmcp/insert-stock-image', 'slot' => 'abilities.media.stock'],
    ['kind' => 'ability', 'name' => 'wpmcp/install-package-from-zip', 'slot' => 'abilities.packages.zip_install'],
    ['kind' => 'ability', 'name' => 'wpmcp/woo-ops', 'slot' => 'abilities.woocommerce.catalog_ops'],
    ['kind' => 'ability', 'name' => 'wpmcp/woo-read', 'slot' => 'abilities.woocommerce.catalog_read'],
    ['kind' => 'ability', 'name' => 'wpmcp/woo-write', 'slot' => 'abilities.woocommerce.catalog_write'],
    [
        'kind' => 'span',
        'name' => 'boot_cli_job',
        'start' => "            // The WP-Cron executor for dispatch-cli-job's scheduled events",
        'end' => "            add_action(Run_Cli_Job::HOOK, [new Run_Cli_Job(), 'handle']);",
    ],
    [
        'kind' => 'span',
        'name' => 'boot_chat',
        // Only the routes: the conversation type and the purge that removes
        // conversations with their owner stay public, so a site that drops
        // back to the free build keeps cleaning up after the chat.
        'start' => '            // The route hook resolves the tier inside the callback and',
        'end' => '            });',
    ],
    [
        'kind' => 'span',
        'name' => 'admin_menu_chat',
        'start' => '        // In-admin AI chat (issue #73): the chat drives the same governed',
        'end' => '        }',
    ],
];

/** tests/pro subpaths that stay public, and where they go. */
const PUBLIC_PRO_TESTS = ['tests/pro/Freemius/' => 'tests/free/Freemius/'];

// ---------------------------------------------------------------------------

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "pro-split: {$message}\n");
    exit($code);
}

/** @return array{include: list<string>, exclude: list<string>} */
function load_manifest(string $root): array
{
    $file = $root . '/' . MANIFEST;
    if (! is_file($file)) {
        fail(MANIFEST . ' not found under ' . $root);
    }
    $include = [];
    $exclude = [];
    foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim((string) $line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, '!')) {
            $exclude[] = substr($line, 1);
            continue;
        }
        $include[] = $line;
    }
    return ['include' => $include, 'exclude' => $exclude];
}

function path_matches(string $path, string $pattern): bool
{
    return $path === $pattern || (str_ends_with($pattern, '/') && str_starts_with($path, $pattern));
}

/** @param array{include: list<string>, exclude: list<string>} $manifest */
function is_pro_path(string $path, array $manifest): bool
{
    foreach ($manifest['exclude'] as $pattern) {
        if (path_matches($path, $pattern)) {
            return false;
        }
    }
    foreach ($manifest['include'] as $pattern) {
        if (path_matches($path, $pattern)) {
            return true;
        }
    }
    return false;
}

/** @return list<string> repo-relative files under $dir */
function list_files(string $root, string $dir): array
{
    $base = $root . '/' . $dir;
    if (! is_dir($base)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $out[] = substr((string) $file, strlen($root) + 1);
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// Class index and references (tokenizer based; no autoloading, no WordPress).

/**
 * @return array{declares: list<string>, refs: array<string, string>} refs: FQCN => 'hard'|'soft'
 */
function scan_php(string $code): array
{
    $t = token_get_all($code);
    $n = count($t);
    $ns = '';
    $uses = [];
    $refs = [];
    $declares = [];
    $add = static function (string $name, string $kind) use (&$refs): void {
        $name = ltrim($name, '\\');
        if ('' === $name) {
            return;
        }
        if ('hard' === $kind || ! isset($refs[ $name ])) {
            $refs[ $name ] = $kind;
        }
    };
    $resolve = static function (string $name) use (&$ns, &$uses): string {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($uses[ $first ])) {
            return $uses[ $first ] . substr($name, strlen($first));
        }
        return ('' === $ns ? '' : $ns . '\\') . $name;
    };
    $prev_sig = static function (int $i) use ($t): mixed {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($t[ $k ]) && in_array($t[ $k ][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $t[ $k ];
        }
        return null;
    };
    $depth = 0;
    for ($i = 0; $i < $n; $i++) {
        $x = $t[ $i ];
        if ('{' === $x || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            continue;
        }
        if ('}' === $x) {
            $depth--;
            continue;
        }
        if (! is_array($x)) {
            continue;
        }
        switch ($x[0]) {
            case T_NAMESPACE:
                $ns = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($t[ $j ]) && in_array($t[ $j ][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $ns = $t[ $j ][1];
                        $i = $j;
                        break;
                    }
                    if (';' === $t[ $j ] || '{' === $t[ $j ]) {
                        break;
                    }
                }
                break;
            case T_USE:
                if (0 !== $depth && ! ('' !== $ns && 1 === $depth)) {
                    // Trait use inside a class body (depth >= 1) or closure use.
                    $p = $prev_sig($i);
                    if (')' === $p) {
                        break;
                    }
                    if ($depth >= 1) {
                        // trait import: a hard reference
                        $buf = '';
                        for ($j = $i + 1; $j < $n && ';' !== $t[ $j ] && '{' !== $t[ $j ]; $j++) {
                            $buf .= is_array($t[ $j ]) ? $t[ $j ][1] : $t[ $j ];
                        }
                        foreach (explode(',', $buf) as $part) {
                            if ('' !== trim($part)) {
                                $add($resolve(trim($part)), 'hard');
                            }
                        }
                        break;
                    }
                }
                $p = $prev_sig($i);
                if (')' === $p) {
                    break;
                }
                $buf = '';
                for ($j = $i + 1; $j < $n && ';' !== $t[ $j ]; $j++) {
                    $buf .= is_array($t[ $j ]) ? $t[ $j ][1] : $t[ $j ];
                }
                if (1 === preg_match('/^\s*(function|const)\s/', $buf)) {
                    break;
                }
                // Group use: `use A\B\{C, D as E};`
                $open = strpos($buf, '{');
                $close = strrpos($buf, '}');
                if (false !== $open && false !== $close && $close > $open) {
                    $prefix = trim(trim(substr($buf, 0, $open)), '\\');
                    foreach (explode(',', substr($buf, $open + 1, $close - $open - 1)) as $part) {
                        $bits = preg_split('/\s+as\s+/i', trim($part));
                        if (! is_array($bits) || '' === $bits[0]) {
                            continue;
                        }
                        $fq = $prefix . '\\' . trim($bits[0], '\\');
                        $alias = isset($bits[1]) ? trim($bits[1]) : substr((string) strrchr('\\' . $fq, '\\'), 1);
                        $uses[ strtolower($alias) ] = $fq;
                        $add($fq, 'soft');
                    }
                    $i = $j;
                    break;
                }
                if (str_contains($buf, '{')) {
                    break;
                }
                foreach (explode(',', $buf) as $part) {
                    $part = trim($part);
                    if (1 === preg_match('/^\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?$/i', $part, $m)) {
                        $fq = ltrim($m[1], '\\');
                        $alias = $m[2] ?? substr((string) strrchr('\\' . $fq, '\\'), 1);
                        $uses[ strtolower($alias) ] = $fq;
                        $add($fq, 'soft');
                    }
                }
                $i = $j;
                break;
            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                $p = $prev_sig($i);
                if (is_array($p) && in_array($p[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    break;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($t[ $j ]) && T_STRING === $t[ $j ][0]) {
                        $declares[] = ('' === $ns ? '' : $ns . '\\') . $t[ $j ][1];
                        break;
                    }
                    if ('{' === $t[ $j ] || '(' === $t[ $j ]) {
                        break;
                    }
                }
                break;
            case T_NAME_FULLY_QUALIFIED:
            case T_NAME_QUALIFIED:
                $add($resolve($x[1]), 'hard');
                break;
            case T_STRING:
                if (1 !== preg_match('/^[A-Z]/', $x[1])) {
                    break;
                }
                $p = $prev_sig($i);
                if (is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    break;
                }
                $add($resolve($x[1]), 'hard');
                break;
            case T_CONSTANT_ENCAPSED_STRING:
                $s = stripcslashes(substr($x[1], 1, -1));
                if (preg_match_all('/\\\\?(WPMCP\\\\[\w\\\\]+)/', $s, $mm) > 0) {
                    foreach ($mm[1] as $c) {
                        $add($c, 'soft');
                    }
                }
                break;
        }
    }
    return ['declares' => $declares, 'refs' => $refs];
}

// ---------------------------------------------------------------------------
// Plugin.php extraction.

/** @return array<string, array{start: int, end: int, doc: int}> method => 0-based line range (doc = first docblock line) */
function method_ranges(string $src): array
{
    $t = token_get_all($src);
    $n = count($t);
    $depth = 0;
    $cur = null;
    $start = 0;
    $doc = 0;
    $out = [];
    $last_line = 1;
    for ($i = 0; $i < $n; $i++) {
        $x = $t[ $i ];
        if (is_array($x)) {
            $last_line = $x[2] + substr_count($x[1], "\n");
        }
        if (is_array($x) && T_FUNCTION === $x[0] && 1 === $depth && null === $cur) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($t[ $j ]) && T_STRING === $t[ $j ][0]) {
                    $cur = $t[ $j ][1];
                    break;
                }
                if ('(' === $t[ $j ]) {
                    break;
                }
            }
            // Walk back over modifiers and the docblock.
            $first = $x[2];
            $doc = $x[2];
            for ($k = $i - 1; $k >= 0; $k--) {
                $y = $t[ $k ];
                if (is_array($y) && in_array($y[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                    $first = $y[2];
                    $doc = $y[2];
                    continue;
                }
                if (is_array($y) && T_WHITESPACE === $y[0]) {
                    continue;
                }
                if (is_array($y) && T_DOC_COMMENT === $y[0]) {
                    $doc = $y[2];
                }
                break;
            }
            $start = $first;
        }
        if ('{' === $x || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        }
        if ('}' === $x) {
            $depth--;
            if (1 === $depth && null !== $cur) {
                $out[ $cur ] = ['start' => $start - 1, 'end' => $last_line - 1, 'doc' => $doc - 1];
                $cur = null;
            }
        }
        if (';' === $x && 1 === $depth && null !== $cur) {
            // abstract or interface method without a body
            $cur = null;
        }
    }
    return $out;
}

/**
 * The slot id for a moved method: never the method name itself, so no
 * build that removes the method still names it (the wp.org free-tier gate
 * checks for exactly that). register_x_abilities -> abilities.x,
 * register_x_runtime_hooks -> runtime.x.
 */
function slot_id(string $method): string
{
    if (1 === preg_match('/^register_(.+)_abilities$/', $method, $m)) {
        return 'abilities.' . $m[1];
    }
    if (1 === preg_match('/^register_(.+)_runtime_hooks$/', $method, $m)) {
        return 'runtime.' . $m[1];
    }
    return 'method.' . $method;
}

/** Index of the matching close paren for the open paren at $open in $s. */
function match_paren(string $s, int $open): int
{
    $depth = 0;
    $len = strlen($s);
    for ($i = $open; $i < $len; $i++) {
        if ('(' === $s[ $i ]) {
            $depth++;
        } elseif (')' === $s[ $i ]) {
            $depth--;
            if (0 === $depth) {
                return $i;
            }
        }
    }
    fail('unbalanced parentheses in Plugin.php');
}

/**
 * Cut the Pro slots out of Plugin.php.
 *
 * @return array{plugin: string, slots: string, count: int}|null null when Plugin.php is already split
 */
function extract_plugin_slots(string $src): ?array
{
    $has_any = false;
    foreach (PLUGIN_SLOTS as $slot) {
        $needle = match ($slot['kind']) {
            'method' => 'function ' . $slot['name'] . '(',
            'ability' => "'" . $slot['name'] . "',",
            default => (string) $slot['start'],
        };
        if (str_contains($src, $needle)) {
            $has_any = true;
            break;
        }
    }
    if (! $has_any) {
        return null;
    }

    $lines = explode("\n", $src);
    $methods = [];   // name => [signature params, body lines]
    $pieces = [];    // slot name => ['lines' => list<string>, 'needs_registrar' => bool]
    $remove = [];    // 0-based line indexes to delete
    $replace = [];   // 0-based line index => replacement line (for spans and abilities)
    $method_names = [];
    foreach (PLUGIN_SLOTS as $slot) {
        if ('method' === $slot['kind']) {
            $method_names[] = $slot['name'];
        }
    }

    $ranges = method_ranges($src);
    foreach (PLUGIN_SLOTS as $slot) {
        if ('method' !== $slot['kind']) {
            continue;
        }
        $name = $slot['name'];
        if (! isset($ranges[ $name ])) {
            fail("Plugin.php has no method {$name}; update PLUGIN_SLOTS");
        }
        $r = $ranges[ $name ];
        $block = array_slice($lines, $r['doc'], $r['end'] - $r['doc'] + 1);
        $methods[ $name ] = $block;
        for ($k = $r['doc']; $k <= $r['end']; $k++) {
            $remove[ $k ] = true;
        }
        // A blank line before the docblock goes too, so no double blank line is left.
        if ($r['doc'] > 0 && '' === trim($lines[ $r['doc'] - 1 ]) && isset($lines[ $r['end'] + 1 ]) && '' === trim($lines[ $r['end'] + 1 ])) {
            $remove[ $r['doc'] - 1 ] = true;
        }
    }

    foreach (PLUGIN_SLOTS as $slot) {
        if ('ability' === $slot['kind']) {
            $name = $slot['name'];
            $at = null;
            foreach ($lines as $k => $line) {
                if ("            '" . $name . "'," === $line && isset($lines[ $k - 1 ]) && str_ends_with($lines[ $k - 1 ], '$registrar->register(new Ability(')) {
                    if (null !== $at) {
                        fail("ability {$name} is registered twice in Plugin.php");
                    }
                    $at = $k - 1;
                }
            }
            if (null === $at) {
                fail("Plugin.php registers no ability {$name}; update PLUGIN_SLOTS");
            }
            $indent = (string) preg_replace('/\S.*$/', '', $lines[ $at ]);
            $end = null;
            for ($k = $at + 1, $c = count($lines); $k < $c; $k++) {
                if ($indent . '));' === $lines[ $k ]) {
                    $end = $k;
                    break;
                }
            }
            if (null === $end) {
                fail("cannot find the end of ability {$name}");
            }
            $body = array_slice($lines, $at, $end - $at + 1);
            $prefix = [];
            $joined = implode("\n", $body);
            if (1 === preg_match('/\[\s*\$(\w+)\s*,\s*\'\w+\'\s*\]/', $joined, $hm)) {
                $var = $hm[1];
                // The handler variable's assignment, when this statement is its only user.
                $uses_elsewhere = 0;
                $assign = null;
                foreach ($lines as $k => $line) {
                    if ($k >= $at && $k <= $end) {
                        continue;
                    }
                    if (1 === preg_match('/^\s*\$' . $var . '\s*=\s*new\s/', $line)) {
                        $assign = $k;
                        continue;
                    }
                    if (1 === preg_match('/\$' . $var . '\b/', $line)) {
                        $uses_elsewhere++;
                    }
                }
                if (null === $assign) {
                    fail("ability {$name}: no assignment for handler \${$var}");
                }
                if (0 !== $uses_elsewhere) {
                    fail("ability {$name}: handler \${$var} is used outside its registration");
                }
                $prefix[] = $indent . trim($lines[ $assign ]);
                $remove[ $assign ] = true;
                if (isset($lines[ $assign + 1 ]) && '' === trim($lines[ $assign + 1 ]) && $assign + 1 !== $at) {
                    $remove[ $assign + 1 ] = true;
                }
            }
            // Its own id, not the ability name: a build without the ability
            // must not name it anywhere.
            $slot_name = (string) ($slot['slot'] ?? 'ability.' . md5($name));
            $pieces[ $slot_name ] = ['lines' => array_merge($prefix, $body), 'registrar' => true];
            for ($k = $at; $k <= $end; $k++) {
                $remove[ $k ] = true;
            }
            unset($remove[ $at ]);
            $replace[ $at ] = $indent . SLOT_MARKER . var_export($slot_name, true) . ', $this->edition_context($registrar));';
        }
        if ('span' === $slot['kind']) {
            $found = array_keys(array_filter($lines, static fn (string $l): bool => str_starts_with($l, (string) $slot['start'])));
            if (1 !== count($found)) {
                fail("span {$slot['name']}: " . count($found) . ' lines start with its start anchor, need exactly 1; update PLUGIN_SLOTS');
            }
            $start = $found[0];
            $end = null;
            for ($k = $start + 1, $c = count($lines); $k < $c; $k++) {
                if ($lines[ $k ] === $slot['end']) {
                    $end = $k;
                    break;
                }
            }
            if (null === $end) {
                fail("span {$slot['name']}: end line not found");
            }
            $indent = (string) preg_replace('/\S.*$/', '', $lines[ $start ]);
            $pieces[ $slot['name'] ] = ['lines' => array_slice($lines, $start, $end - $start + 1), 'registrar' => false];
            for ($k = $start; $k <= $end; $k++) {
                $remove[ $k ] = true;
            }
            unset($remove[ $start ]);
            $replace[ $start ] = $indent . SLOT_MARKER . var_export($slot['name'], true) . ', $this->edition_context(null));';
        }
    }

    // Rebuild Plugin.php.
    $out = [];
    foreach ($lines as $k => $line) {
        if (isset($replace[ $k ])) {
            $out[] = $replace[ $k ];
            continue;
        }
        if (isset($remove[ $k ])) {
            continue;
        }
        $out[] = $line;
    }
    $plugin = implode("\n", $out);

    // Call sites of moved methods: $this->m($registrar) and $this->m().
    foreach ($method_names as $name) {
        $plugin = (string) preg_replace_callback(
            '/\$this->' . preg_quote($name, '/') . '\((\$registrar)?\)/',
            static fn (array $m): string => SLOT_MARKER . var_export(slot_id($name), true) . ', $this->edition_context(' . (isset($m[1]) && '' !== $m[1] ? '$registrar' : 'null') . '))',
            $plugin
        );
        if (str_contains($plugin, '$this->' . $name . '(')) {
            fail("Plugin.php still calls {$name}() in a form the split does not rewrite");
        }
    }

    // Comments that still name a moved method name its slot instead.
    foreach ($method_names as $name) {
        $plugin = (string) preg_replace('/\b' . preg_quote($name, '/') . '\(\)/', 'the ' . slot_id($name) . ' edition slot', $plugin);
        $plugin = (string) preg_replace('/\b' . preg_quote($name, '/') . '\b/', slot_id($name), $plugin);
    }

    // The slot plumbing, added once after registrar().
    $ranges = method_ranges($plugin);
    if (! isset($ranges['registrar'])) {
        fail('Plugin.php has no registrar() method to anchor the edition plumbing');
    }
    $plines = explode("\n", $plugin);
    $plumbing = [
        '',
        '    /**',
        '     * The edition slots: Pro registers its part of the surface through the',
        '     * wpmcp_edition_slots filter (src/Edition/Edition.php). Built once per',
        '     * instance, on first use, so the free build pays for one filter call.',
        '     */',
        '    private function edition(): \\WPMCP\\Edition\\Edition',
        '    {',
        '        return $this->edition ??= \\WPMCP\\Edition\\Edition::from_hooks();',
        '    }',
        '',
        '    /** Run one edition slot outside the boot sequence (tests replay a slot this way). */',
        '    public function run_edition_slot(string $slot, ?Registrar $registrar = null): void',
        '    {',
        '        $this->edition()->run($slot, $this->edition_context($registrar));',
        '    }',
        '',
        '    private function edition_context(?Registrar $registrar): \\WPMCP\\Edition\\Edition_Context',
        '    {',
        '        return new \\WPMCP\\Edition\\Edition_Context(',
        '            $registrar,',
        '            fn (string $group): bool => $this->group_enabled($group),',
        '            function (Registrar $registrar, array $integrations): void {',
        '                $this->register_integrations($registrar, $integrations);',
        '            }',
        '        );',
        '    }',
    ];
    array_splice($plines, $ranges['registrar']['end'] + 1, 0, $plumbing);
    // The property, right before the instance() method's docblock.
    $ranges = method_ranges(implode("\n", $plines));
    $anchor = $ranges['instance']['doc'] ?? null;
    if (null === $anchor) {
        fail('Plugin.php has no instance() method to anchor the edition property');
    }
    array_splice($plines, $anchor, 0, ['    private ?\\WPMCP\\Edition\\Edition $edition = null;', '']);
    $plugin = implode("\n", $plines);

    // Imports Plugin.php no longer uses.
    $plugin = prune_unused_imports($plugin);

    // The slot class.
    $slots = render_slot_class($src, $methods, $pieces, $method_names);

    return ['plugin' => $plugin, 'slots' => $slots, 'count' => count($methods) + count($pieces)];
}

function prune_unused_imports(string $src): string
{
    if (1 !== preg_match('/^namespace [^;]+;\n/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $src;
    }
    $lines = explode("\n", $src);
    $class_line = null;
    foreach ($lines as $k => $line) {
        if (1 === preg_match('/^(final\s+|abstract\s+)?class\s/', $line)) {
            $class_line = $k;
            break;
        }
    }
    if (null === $class_line) {
        return $src;
    }
    $body = implode("\n", array_slice($lines, $class_line));
    // Docblocks and comments still count as uses for @see style references only
    // when the name appears in code; strip comments first.
    $code = '';
    foreach (token_get_all('<?php ' . $body) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    $out = [];
    foreach ($lines as $k => $line) {
        if ($k < $class_line && 1 === preg_match('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;$/', $line, $um)) {
            $alias = $um[2] ?? substr((string) strrchr('\\' . $um[1], '\\'), 1);
            if (1 !== preg_match('/(?<![\w\\\\$])' . preg_quote($alias, '/') . '\b/', $code)) {
                continue;
            }
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/**
 * @param array<string, list<string>>                                   $methods
 * @param array<string, array{lines: list<string>, registrar: bool}>    $pieces
 * @param list<string>                                                  $method_names
 */
function render_slot_class(string $plugin_src, array $methods, array $pieces, array $method_names): string
{
    // Imports: Plugin.php's, filtered to the names the moved code uses.
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;$/m', $plugin_src, $um, PREG_SET_ORDER);
    $all_code = implode("\n", array_merge(...array_values($methods), ...array_map(static fn (array $p): array => $p['lines'], array_values($pieces))));
    $imports = [];
    foreach ($um as $u) {
        $alias = $u[2] ?? substr((string) strrchr('\\' . $u[1], '\\'), 1);
        if (1 === preg_match('/(?<![\w\\\\$])' . preg_quote($alias, '/') . '\b/', $all_code)) {
            $imports[] = $u[0];
        }
    }
    $imports[] = 'use WPMCP\\Edition\\Edition_Context;';
    $imports[] = 'use WPMCP\\Edition\\Edition_Slot;';
    $imports[] = 'use WPMCP\\MCP\\Registrar;';
    $imports = array_values(array_unique($imports));
    sort($imports);

    $rewrite = static function (string $code) use ($method_names): string {
        $code = str_replace('$this->group_enabled(', '$context->group_enabled(', $code);
        $code = str_replace('$this->register_integrations(', '$context->register_integrations(', $code);
        $code = str_replace('self::page_title(', '\\WPMCP\\Plugin::page_title(', $code);
        foreach ($method_names as $name) {
            $code = (string) preg_replace('/\$this->' . preg_quote($name, '/') . '\(\$registrar\)/', '$this->' . $name . '($registrar, $context)', $code);
            $code = (string) preg_replace('/\$this->' . preg_quote($name, '/') . '\(\)/', '$this->' . $name . '($context)', $code);
        }
        if (1 === preg_match('/\$this->(?!' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $method_names)) . ')\w+/', $code, $bad)) {
            fail('moved Plugin.php code uses ' . $bad[0] . ', which the slot class does not have');
        }
        if (1 === preg_match('/\bself::(?!class\b)\w+/', $code, $bad)) {
            fail('moved Plugin.php code uses ' . $bad[0] . ', which the slot class does not have');
        }
        return $code;
    };

    $arms = [];
    $bodies = [];
    foreach ($methods as $name => $block) {
        $code = $rewrite(implode("\n", $block));
        $has_registrar = 1 === preg_match('/function ' . preg_quote($name, '/') . '\(\s*Registrar \$registrar\s*\)/', $code);
        if ($has_registrar) {
            $code = (string) preg_replace('/function ' . preg_quote($name, '/') . '\(\s*Registrar \$registrar\s*\)/', 'function ' . $name . '(Registrar $registrar, Edition_Context $context)', $code, 1);
            $arms[] = "            '" . slot_id($name) . "' => \$this->with_registrar(\$context, fn (Registrar \$registrar) => \$this->{$name}(\$registrar, \$context)),";
        } else {
            $code = (string) preg_replace('/function ' . preg_quote($name, '/') . '\(\s*\)/', 'function ' . $name . '(Edition_Context $context)', $code, 1, $count);
            if (1 !== $count) {
                fail("method {$name} has a signature the split does not handle");
            }
            $arms[] = "            '" . slot_id($name) . "' => \$this->run_plain(fn () => \$this->{$name}(\$context)),";
        }
        $code = (string) preg_replace('/^(\s+)public function /m', '$1private function ', $code, 1);
        $bodies[] = $code;
    }
    foreach ($pieces as $slot => $piece) {
        $method = 'slot_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($slot));
        $lines = $piece['lines'];
        // Re-indent to method body depth (8 spaces).
        $min = PHP_INT_MAX;
        foreach ($lines as $line) {
            if ('' !== trim($line)) {
                $min = min($min, strlen($line) - strlen(ltrim($line)));
            }
        }
        $lines = array_map(static fn (string $l): string => '' === trim($l) ? '' : '        ' . substr($l, $min), $lines);
        $code = $rewrite(implode("\n", $lines));
        if ($piece['registrar']) {
            $bodies[] = "    private function {$method}(Registrar \$registrar, Edition_Context \$context): void\n    {\n{$code}\n    }";
            $arms[] = '            ' . var_export($slot, true) . " => \$this->with_registrar(\$context, fn (Registrar \$registrar) => \$this->{$method}(\$registrar, \$context)),";
        } else {
            $bodies[] = "    private function {$method}(Edition_Context \$context): void\n    {\n{$code}\n    }";
            $arms[] = '            ' . var_export($slot, true) . " => \$this->run_plain(fn () => \$this->{$method}(\$context)),";
        }
    }

    $imports_s = implode("\n", $imports);
    $arms_s = implode("\n", $arms);
    $bodies_s = implode("\n\n", $bodies);
    return <<<PHP
<?php

// phpcs:ignoreFile -- generated by bin/pro-split.php from src/Plugin.php; the moved code keeps its own style.

namespace WPMCP\\Pro;

if (! defined('ABSPATH')) {
    exit;
}

{$imports_s}

/**
 * The Pro part of the Plugin.php surface: registration methods, ability
 * rows and boot wiring moved here by bin/pro-split.php. Plugin.php keeps a
 * slot call at each place this code ran, in the same order, and
 * src/Pro/bootstrap.php registers this class through wpmcp_edition_slots.
 */
final class Plugin_Slots implements Edition_Slot
{
    public function run(string \$slot, Edition_Context \$context): bool
    {
        return match (\$slot) {
{$arms_s}
            default => false,
        };
    }

    /** @param \\Closure(Registrar): void \$register */
    private function with_registrar(Edition_Context \$context, \\Closure \$register): bool
    {
        if (null === \$context->registrar) {
            return false;
        }
        \$register(\$context->registrar);
        return true;
    }

    /** @param \\Closure(): void \$run */
    private function run_plain(\\Closure \$run): bool
    {
        \$run();
        return true;
    }

{$bodies_s}
}

PHP;
}

// ---------------------------------------------------------------------------
// Paths a moved file builds from __DIR__.

/** Normalise a repo-relative path with ./ and ../ segments; null when it climbs out. */
function normalise_path(string $path): ?string
{
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ('' === $seg || '.' === $seg) {
            continue;
        }
        if ('..' === $seg) {
            if ([] === $out) {
                return null;
            }
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return implode('/', $out);
}

/**
 * Rewrite `__DIR__ . '/x'`, `dirname(__DIR__, N) . '/x'` and bare
 * `dirname(__DIR__, N)` in a file moving from $old to $new so each still
 * points at the same target, or at that target's new home when it moves too.
 * The rewritten form is `__DIR__ . '/../<K times>/<repo path>'`, K being the
 * new file's depth.
 *
 * @param \Closure(string): string $map repo path => repo path after the split
 */
function rewrite_dir_paths(string $code, string $old, string $new, \Closure $map): string
{
    $old_dir = dirname($old);
    $new_depth = count(explode('/', dirname($new)));
    return (string) preg_replace_callback(
        '/(?<![\w>])(dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)|__DIR__)(\s*\.\s*\'([^\'\\\\]*)\')?/',
        static function (array $m) use ($old_dir, $new_depth, $map): string {
            $levels = str_starts_with($m[1], 'dirname') ? (int) (isset($m[2]) && '' !== $m[2] ? $m[2] : 1) : 0;
            $base = $old_dir;
            for ($i = 0; $i < $levels; $i++) {
                $base = '.' === dirname($base) ? '' : dirname($base);
                if ('' === $base) {
                    break;
                }
            }
            $rel = $m[4] ?? '';
            $has_concat = isset($m[3]) && '' !== $m[3];
            $target = normalise_path(('' === $base ? '' : $base . '/') . ltrim($rel, '/'));
            if (null === $target) {
                return $m[0];
            }
            $trailing = str_ends_with($rel, '/') ? '/' : '';
            $target = $map($target);
            // `__DIR__ . '/../..'` rather than dirname(): it stays valid in a
            // constant expression.
            $up = rtrim(str_repeat('../', $new_depth), '/');
            if (! $has_concat && '' === $target) {
                // A bare repository root: keep it a real path (code compares
                // it with resolved file names); dirname() is never valid in a
                // constant expression, so neither was the original.
                return 'dirname(__DIR__, ' . $new_depth . ')';
            }
            return "__DIR__ . '/" . $up . ('' === $target ? '' : '/' . $target) . $trailing . "'";
        },
        $code
    );
}

/** @param list<string> $methods */
function rewrite_moved_method_uses(string $code, array $methods): string
{
    foreach ($methods as $m) {
        $q = preg_quote($m, '/');
        // Plugin::instance()->m() and ->m($registrar)
        $code = (string) preg_replace_callback(
            '/((?:\\\\?WPMCP\\\\)?Plugin::instance\(\))->' . $q . '\((\$\w+)?\)/',
            static fn (array $mm): string => $mm[1] . "->run_edition_slot('" . slot_id($m) . "'" . (isset($mm[2]) && '' !== $mm[2] ? ', ' . $mm[2] : '') . ')',
            $code
        );
        // new ReflectionMethod(Plugin::class, 'm')
        $code = (string) preg_replace('/(ReflectionMethod\(\s*)(?:\\\\?WPMCP\\\\)?Plugin::class(\s*,\s*\'' . $q . '\')/', '$1\\\\WPMCP\\\\Pro\\\\Plugin_Slots::class$2', $code);
        // A source assertion on the call text.
        $code = str_replace("'" . $m . "()'", "'" . slot_id($m) . "'", $code);
    }
    return $code;
}

// ---------------------------------------------------------------------------
// Planning.

/**
 * @return array{moves: array<string, string>, writes: array<string, string>, notes: list<string>}
 */
function plan(string $root): array
{
    $manifest = load_manifest($root);
    $moves = [];
    $writes = [];
    $notes = [];

    // 1. Plugin.php.
    $plugin_file = $root . '/src/Plugin.php';
    $extract = extract_plugin_slots((string) file_get_contents($plugin_file));
    if (null === $extract) {
        $notes[] = 'Plugin.php: already split';
    } else {
        if (is_file($root . '/' . SLOTS_FILE)) {
            fail(SLOTS_FILE . ' exists but Plugin.php still holds Pro slots: conflict');
        }
        $writes['src/Plugin.php'] = $extract['plugin'];
        $writes[ SLOTS_FILE ] = $extract['slots'];
        $notes[] = 'Plugin.php: ' . $extract['count'] . ' slots moved to ' . SLOTS_FILE;
    }

    // 2. src/ paths and other public paths the manifest lists (skills, assets).
    foreach (['src', 'assets'] as $dir) {
        foreach (list_files($root, $dir) as $path) {
            if (! is_pro_path($path, $manifest)) {
                continue;
            }
            $moves[ $path ] = PRO_DIR . '/' . $path;
        }
    }

    // 3. Tests.
    $pro_classes = pro_class_index($root, $manifest, $writes);
    foreach (list_files($root, 'tests/pro') as $path) {
        $public = null;
        foreach (PUBLIC_PRO_TESTS as $from => $to) {
            if (str_starts_with($path, $from)) {
                $public = $to . substr($path, strlen($from));
            }
        }
        if (null !== $public) {
            $moves[ $path ] = $public;
            if (str_ends_with($path, '.php')) {
                $writes[ $public ] = str_replace('namespace WPMCP\\Tests\\Pro\\', 'namespace WPMCP\\Tests\\Free\\', (string) file_get_contents($root . '/' . $path));
            }
            continue;
        }
        $moves[ $path ] = PRO_DIR . '/' . $path;
    }
    foreach (list_files($root, 'tests/free') as $path) {
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        $scan = scan_php((string) file_get_contents($root . '/' . $path));
        foreach ($scan['refs'] as $ref => $kind) {
            // A class-name string (a class_exists() probe) does not tie a
            // public test to Pro; code that loads the class does.
            if ('hard' === $kind && isset($pro_classes[ $ref ])) {
                $moves[ $path ] = PRO_DIR . '/' . $path;
                break;
            }
        }
    }

    // Moved PHP files keep pointing at the files they load.
    $map = static function (string $target) use (&$moves): string {
        if (isset($moves[ $target ])) {
            return $moves[ $target ];
        }
        foreach ($moves as $from => $to) {
            if (str_starts_with($from, $target . '/')) {
                // A directory: it moves when every file in it moves to the same place.
                return $target;
            }
        }
        return $target;
    };
    foreach ($moves as $from => $to) {
        if (! str_ends_with($from, '.php')) {
            continue;
        }
        $code = $writes[ $to ] ?? (string) file_get_contents($root . '/' . $from);
        $rewritten = rewrite_dir_paths($code, $from, $to, $map);
        if ($rewritten !== $code) {
            $writes[ $to ] = $rewritten;
        }
    }

    // Tests that call or read a moved Plugin.php method reach it through
    // the edition slot (a call) or on the slot class (its source).
    $moved_methods = [];
    foreach (PLUGIN_SLOTS as $slot) {
        if ('method' === $slot['kind']) {
            $moved_methods[] = $slot['name'];
        }
    }
    if (null !== $extract) {
        foreach (array_merge(list_files($root, 'tests/free'), list_files($root, 'tests/pro')) as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $target = $moves[ $path ] ?? $path;
            $code = $writes[ $target ] ?? (string) file_get_contents($root . '/' . $path);
            $new = rewrite_moved_method_uses($code, $moved_methods);
            if ($new !== $code) {
                $writes[ $target ] = $new;
            }
        }
    }

    // 5. The flavor lists that removed what just moved.
    $src_moves = array_filter($moves, static fn (string $to, string $from): bool => str_starts_with($from, 'src/') || str_starts_with($from, 'assets/'), ARRAY_FILTER_USE_BOTH);
    $moved_away = static function (string $path) use ($root, $src_moves): bool {
        $path = rtrim($path, '/');
        if (isset($src_moves[ $path ])) {
            return true;
        }
        // A directory moves away when it holds files and all of them move.
        $files = is_dir($root . '/' . $path) ? list_files($root, $path) : [];
        if ([] === $files) {
            return false;
        }
        foreach ($files as $f) {
            if (! isset($src_moves[ $f ])) {
                return false;
            }
        }
        return true;
    };
    if (null !== $extract) {
        $methods = [];
        $abilities = [];
        foreach (PLUGIN_SLOTS as $slot) {
            if ('method' === $slot['kind']) {
                $methods[] = $slot['name'];
            } elseif ('ability' === $slot['kind']) {
                $abilities[] = $slot['name'];
            }
        }
        $pruned = prune_flavor_lists($root, ['src/Plugin.php' => (string) file_get_contents($plugin_file)], $extract['plugin'], $methods, $abilities, $moved_away);
        foreach ($pruned['problems'] as $problem) {
            fail('flavor lists: ' . $problem);
        }
        $writes += $pruned['writes'];
        $notes[] = 'flavor lists: ' . count($pruned['dropped']) . ' entries dropped';
        foreach ($pruned['dropped'] as $d) {
            $notes[] = '  dropped ' . $d;
        }
    }

    // 6. Test tooling lists. bin/legs.tsv names a live group's test files:
    //    those keep their row, at their new path.
    $legs_file = $root . '/bin/legs.tsv';
    if (is_file($legs_file)) {
        $legs = (string) file_get_contents($legs_file);
        $new_legs = (string) preg_replace_callback('#tests/[A-Za-z0-9_./-]+\.php#', static fn (array $m): string => $moves[ $m[0] ] ?? $m[0], $legs);
        if ($new_legs !== $legs) {
            $writes['bin/legs.tsv'] = $new_legs;
        }
    }
    $lists = prune_test_lists($root, $moves);
    $writes += $lists['writes'];
    if ($lists['dropped'] > 0) {
        $notes[] = 'test lists: ' . $lists['dropped'] . ' entries for moved tests dropped';
    }


    // Conflicts and idempotency.
    foreach ($moves as $from => $to) {
        if (is_file($root . '/' . $to) && ! isset($writes[ $to ])) {
            fail("conflict: {$from} exists and so does {$to}");
        }
    }
    return ['moves' => $moves, 'writes' => $writes, 'notes' => $notes];
}

/**
 * Pro classes: every class declared in a manifest path, plus the slot class.
 *
 * @param array{include: list<string>, exclude: list<string>} $manifest
 * @param array<string, string>                                $writes
 * @return array<string, string> FQCN => file
 */
function pro_class_index(string $root, array $manifest, array $writes): array
{
    $out = [];
    $files = array_merge(list_files($root, 'src'), list_files($root, PRO_DIR . '/src'));
    foreach ($files as $path) {
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        $is_pro = str_starts_with($path, PRO_DIR . '/') || is_pro_path($path, $manifest);
        if (! $is_pro) {
            continue;
        }
        foreach (scan_php((string) file_get_contents($root . '/' . $path))['declares'] as $class) {
            $out[ $class ] = $path;
        }
    }
    // Manifest files that are gone (a split tree without pro/) still name
    // their class: src/<path>.php is WPMCP\<path> under PSR-4.
    foreach ($manifest['include'] as $pattern) {
        if (str_starts_with($pattern, 'src/') && str_ends_with($pattern, '.php')) {
            $class = 'WPMCP\\' . str_replace('/', '\\', substr($pattern, 4, -4));
            $out[ $class ] ??= $pattern;
        }
    }
    $out['WPMCP\\Pro\\' . SLOTS_CLASS] = SLOTS_FILE;
    return $out;
}

// ---------------------------------------------------------------------------
// Flavor lists: the directory and WooCommerce strips remove Pro code from the
// public tree. Once that code lives in pro/, the entries that removed it have
// nothing left to act on, so the split drops them in the same change.

const FLAVOR_FILES = [
    'scripts/flavors/wporg/strip.php',
    'scripts/flavors/wporg/policy.php',
    'scripts/flavors/wporg/manifest.php',
    'scripts/flavors/woocommerce/manifest.php',
];

/**
 * Top-level `$edits[...] ...;` statements of strip.php: [first line, last line] (0-based).
 *
 * @return list<array{0: int, 1: int, 2: string}>
 */
function edit_statements(string $src): array
{
    $t = token_get_all($src);
    $n = count($t);
    $out = [];
    $depth = 0;
    $line = 1;
    for ($i = 0; $i < $n; $i++) {
        $x = $t[ $i ];
        if (is_array($x)) {
            $line = $x[2];
        }
        if (in_array($x, ['{', '(', '['], true) || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            continue;
        }
        if (in_array($x, ['}', ')', ']'], true)) {
            $depth--;
            continue;
        }
        if (0 !== $depth || ! is_array($x) || T_VARIABLE !== $x[0] || ! in_array($x[1], ['$edits', '$plugin_edits'], true)) {
            continue;
        }
        $start = $x[2];
        $d = 0;
        $end = $start;
        for ($j = $i + 1; $j < $n; $j++) {
            $y = $t[ $j ];
            if (is_array($y)) {
                $end = $y[2] + substr_count($y[1], "\n");
            }
            if (in_array($y, ['{', '(', '['], true)) {
                $d++;
            } elseif (in_array($y, ['}', ')', ']'], true)) {
                $d--;
            } elseif (';' === $y && 0 === $d) {
                break;
            }
        }
        $out[] = [$start - 1, $end - 1, $x[1]];
        $i = $j;
    }
    return $out;
}

/**
 * The elements of an array literal assigned by $code (`$x = [ e1, e2 ];`),
 * as [first line, last line, element code] relative to $code, or null when
 * $code is not such an assignment or two elements share a line.
 *
 * @return list<array{0: int, 1: int, 2: string}>|null
 */
function list_elements(string $code): ?array
{
    if (1 !== preg_match('/^\s*\$\w+(\[[^\]]*\])?\s*=\s*\[/', $code)) {
        return null;
    }
    $t = token_get_all('<?php ' . $code);
    $n = count($t);
    $depth = 0;
    $line = 1;
    $next_line = 1 + substr_count(is_array($t[0]) ? $t[0][1] : $t[0], "\n");
    $elements = [];
    $cur = null;
    $seen_assign = false;
    for ($i = 1; $i < $n; $i++) {
        $x = $t[ $i ];
        $text = is_array($x) ? $x[1] : $x;
        // The line this token starts on; $next_line is where the next one does.
        $line = $next_line;
        $next_line += substr_count($text, "\n");
        if ('=' === $x && 0 === $depth) {
            $seen_assign = true;
            continue;
        }
        if (! $seen_assign) {
            continue;
        }
        if (in_array($x, ['[', '(', '{'], true)) {
            $depth++;
            if (1 === $depth) {
                continue;
            }
        } elseif (in_array($x, [']', ')', '}'], true)) {
            $depth--;
            if (0 === $depth) {
                if (null !== $cur && '' !== trim($cur['code'])) {
                    $elements[] = $cur;
                }
                break;
            }
        }
        if (1 === $depth && ',' === $x) {
            if (null !== $cur && '' !== trim($cur['code'])) {
                $elements[] = $cur;
            }
            $cur = null;
            continue;
        }
        if (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) && null === $cur) {
            continue;
        }
        if (null === $cur) {
            $cur = ['start' => $line, 'end' => $line, 'code' => ''];
        }
        $cur['code'] .= $text;
        if (! (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            $cur['end'] = $line + substr_count($text, "\n");
        }
    }
    $out = [];
    $used = [];
    foreach ($elements as $e) {
        for ($l = $e['start']; $l <= $e['end']; $l++) {
            if (isset($used[ $l ])) {
                return null;
            }
            $used[ $l ] = true;
        }
        $out[] = [$e['start'] - 1, $e['end'] - 1, $e['code']];
    }
    return $out;
}

/**
 * $old with every span slot the split cut out of Plugin.php replaced the way
 * the split replaced it in Plugin.php (by its slot call line), so an edit
 * that removed a block holding a span still finds that block.
 */
function transform_by_spans(string $old): string
{
    $lines = explode("\n", $old);
    foreach (PLUGIN_SLOTS as $slot) {
        if ('span' !== $slot['kind']) {
            continue;
        }
        foreach ($lines as $k => $line) {
            if (! str_starts_with($line, (string) $slot['start'])) {
                continue;
            }
            for ($e = $k + 1, $c = count($lines); $e < $c; $e++) {
                if ($lines[ $e ] === $slot['end']) {
                    $indent = (string) preg_replace('/\S.*$/', '', $line);
                    array_splice($lines, $k, $e - $k + 1, [$indent . SLOT_MARKER . var_export($slot['name'], true) . ', $this->edition_context(null));']);
                    break 2;
                }
            }
        }
    }
    return implode("\n", $lines);
}

/**
 * Prune the flavor lists after the move.
 *
 * @param array<string, string> $before repo path => contents before the split (Plugin.php)
 * @param list<string>          $slot_methods
 * @param list<string>          $slot_abilities
 * @return array{writes: array<string, string>, dropped: list<string>, problems: list<string>}
 */
function prune_flavor_lists(string $root, array $before, string $plugin_after, array $slot_methods, array $slot_abilities, \Closure $moved_away): array
{
    $writes = [];
    $dropped = [];
    $problems = [];
    $names = array_fill_keys(array_merge($slot_methods, $slot_abilities), true);
    foreach (FLAVOR_FILES as $file) {
        $path = $root . '/' . $file;
        if (! is_file($path)) {
            continue;
        }
        $src = (string) file_get_contents($path);
        $lines = explode("\n", $src);
        $remove = [];
        $insert = [];

        if (str_ends_with($file, 'strip.php')) {
            foreach (edit_statements($src) as [$a, $b, $var]) {
                $code = implode("\n", array_slice($lines, $a, $b - $a + 1));
                set_error_handler(static function (int $no, string $msg): never {
                    throw new \ErrorException($msg, 0, $no);
                });
                try {
                    $edits = (static function (string $code, string $var): array {
                        $edits = [];
                        $plugin_edits = [];
                        eval($code);
                        return '$plugin_edits' === $var ? ['src/Plugin.php' => $plugin_edits] : $edits;
                    })($code, $var);
                } catch (\Throwable $e) {
                    // A statement built from other variables (an assignment of
                    // a list built above) is plumbing, not an edit: keep it.
                    continue;
                } finally {
                    restore_error_handler();
                }
                if ([] === $edits || [] === array_filter($edits, static fn (mixed $l): bool => is_array($l) && [] !== $l)) {
                    continue;
                }
                foreach ($edits as $target => $list) {
                    $target = (string) $target;
                    if ($moved_away($target)) {
                        $remove += array_fill_keys(range($a, $b), true);
                        $dropped[] = "{$file}:" . ($a + 1) . " edit on {$target} (moved to pro/)";
                        continue;
                    }
                    if ('src/Plugin.php' !== $target) {
                        continue;
                    }
                    $obsolete = 0;
                    foreach ((array) $list as $edit) {
                        [$old, , $expected] = $edit;
                        $pre = substr_count($before['src/Plugin.php'], (string) $old);
                        $post = substr_count($plugin_after, (string) $old);
                        if ($post === (int) $expected) {
                            continue;
                        }
                        if (0 === $post && $pre === (int) $expected) {
                            // Obsolete only when every line it removed is gone
                            // from the split Plugin.php too; a removed line that
                            // stayed public still needs its strip.
                            $kept = array_map('trim', explode("\n", (string) $edit[1]));
                            $residual = [];
                            foreach (explode("\n", (string) $old) as $old_line) {
                                $trimmed = trim($old_line);
                                if ('' === $trimmed || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || in_array($trimmed, ['}', '{', ');', '));', '];', ']', ')', '),', '],', '});', 'return;', 'break;', 'continue;', '} else {'], true) || in_array($trimmed, $kept, true)
                                    // The strip drops imports that point at nothing it ships.
                                    || str_starts_with($trimmed, 'use ')) {
                                    continue;
                                }
                                // Still there as often as before the split: the
                                // split did not take this line, so the edit's
                                // removal of it is still needed.
                                if (substr_count($plugin_after, $old_line) > 0 && substr_count($plugin_after, $old_line) >= substr_count($before['src/Plugin.php'], $old_line)) {
                                    $residual[] = $trimmed;
                                }
                            }
                            if ([] === $residual) {
                                $obsolete++;
                                continue;
                            }
                            // The edit removes a block the split shortened: the
                            // same edit, on the shortened block.
                            $narrowed = transform_by_spans((string) $old);
                            if ($narrowed !== $old && substr_count($plugin_after, $narrowed) === (int) $expected && 1 === count((array) $list)) {
                                $code_out = '$' . ltrim($var, '$') . ('$plugin_edits' === $var ? '[] = ' : "['src/Plugin.php'][] = ") . '[' . var_export($narrowed, true) . ', ' . var_export((string) $edit[1], true) . ', ' . (int) $expected . '];';
                                $remove += array_fill_keys(range($a, $b), true);
                                $insert[ $a ] = $code_out;
                                $dropped[] = "{$file}:" . ($a + 1) . ' Plugin.php edit narrowed to the code that stays public';
                                $obsolete = -1;
                                break;
                            }
                            $problems[] = "{$file}:" . ($a + 1) . ': a Plugin.php edit targets code that moved, but these lines it removes stay public: ' . implode(' | ', array_slice($residual, 0, 4));
                            continue;
                        }
                        $problems[] = "{$file}:" . ($a + 1) . ": a Plugin.php edit matched {$pre} time(s) before the split and {$post} after; fix it by hand";
                    }
                    if (-1 === $obsolete) {
                        continue;
                    }
                    if ($obsolete === count((array) $list)) {
                        $remove += array_fill_keys(range($a, $b), true);
                        $dropped[] = "{$file}:" . ($a + 1) . ' edit on src/Plugin.php (the code it edits moved to pro/)';
                        continue;
                    }
                    if (0 === $obsolete) {
                        continue;
                    }
                    // A list of edits: drop only the obsolete elements.
                    $elements = list_elements($code);
                    if (null === $elements) {
                        $problems[] = "{$file}:" . ($a + 1) . ': part of an edit statement targets code that moved to pro/; split it by hand';
                        continue;
                    }
                    foreach ($elements as [$ea, $eb, $ecode]) {
                        try {
                            $edit = eval('return ' . $ecode . ';');
                        } catch (\Throwable $e) {
                            continue;
                        }
                        if (! is_array($edit) || ! isset($edit[0], $edit[2])) {
                            continue;
                        }
                        if (0 === substr_count($plugin_after, (string) $edit[0]) && substr_count($before['src/Plugin.php'], (string) $edit[0]) === (int) $edit[2]) {
                            $remove += array_fill_keys(range($a + $ea, $a + $eb), true);
                            $dropped[] = "{$file}:" . ($a + $ea + 1) . ' Plugin.php edit (the code it edits moved to pro/)';
                        }
                    }
                }
            }
        }

        // List entries: a quoted path that moved away, a moved method or a moved ability.
        foreach ($lines as $k => $line) {
            if (isset($remove[ $k ]) || 1 !== preg_match("/^\s*'([^']+)',\s*(?:\/\/.*)?$/", $line, $m)) {
                continue;
            }
            $value = $m[1];
            if (isset($names[ $value ]) || (str_starts_with($value, 'src/') && $moved_away($value))) {
                $remove[ $k ] = true;
                $dropped[] = "{$file}:" . ($k + 1) . " {$value}";
            }
        }

        if ([] === $remove && [] === $insert) {
            continue;
        }
        $kept = [];
        foreach ($lines as $k => $line) {
            if (isset($insert[ $k ])) {
                $kept[] = $insert[ $k ];
            }
            if (! isset($remove[ $k ])) {
                $kept[] = $line;
            }
        }
        $writes[ $file ] = implode("\n", $kept);
    }
    return ['writes' => $writes, 'dropped' => $dropped, 'problems' => $problems];
}

// ---------------------------------------------------------------------------
// Test tooling lists name test files by path; a moved test leaves them.

const TEST_LISTS = [
    'phpunit-fast.xml.dist',
    'tests/parallel-shards.tsv',
    'tests/canary-classes.txt',
    'tests/impact-map.json',
];

/**
 * @param array<string, string> $moves
 * @return array{writes: array<string, string>, dropped: int}
 */
function prune_test_lists(string $root, array $moves): array
{
    $writes = [];
    $dropped = 0;
    $fast = ['fast' => [], 'heavy' => []];
    foreach (TEST_LISTS as $file) {
        $path = $root . '/' . $file;
        if (! is_file($path)) {
            continue;
        }
        $lines = explode("\n", (string) file_get_contents($path));
        $out = [];
        $suite = null;
        foreach ($lines as $line) {
            if (1 === preg_match('/<testsuite name="(\w+)">/', $line, $sm)) {
                $suite = $sm[1];
            }
            $hit = null;
            if (1 === preg_match_all('#tests/[A-Za-z0-9_./-]+\.php#', $line, $mm)) {
                foreach ($mm[0] as $candidate) {
                    if (isset($moves[ $candidate ]) && str_starts_with($moves[ $candidate ], PRO_DIR . '/')) {
                        $hit = $candidate;
                        break;
                    }
                }
            }
            if (null === $hit) {
                $out[] = $line;
                continue;
            }
            // A shard bound is a position in the serial order, not a file to
            // run: it keeps its place at the test's new path.
            if ('tests/parallel-shards.tsv' === $file) {
                $out[] = str_replace($hit, $moves[ $hit ], $line);
                continue;
            }
            $dropped++;
            if ('phpunit-fast.xml.dist' === $file && isset($fast[ (string) $suite ])) {
                $fast[ (string) $suite ][] = substr($moves[ $hit ], strlen(PRO_DIR) + 1);
            }
        }
        if (str_ends_with($file, '.json')) {
            // A dropped last element leaves a trailing comma before ] or }.
            for ($k = count($out) - 1; $k > 0; $k--) {
                if (1 === preg_match('/^\s*[\]}]/', $out[ $k ]) && str_ends_with(rtrim($out[ $k - 1 ]), ',')) {
                    $out[ $k - 1 ] = substr(rtrim($out[ $k - 1 ]), 0, -1);
                }
            }
        }
        if ($out !== $lines) {
            $writes[ $file ] = implode("\n", $out);
        }
    }
    if ([] !== $fast['fast'] || [] !== $fast['heavy']) {
        $xml = "<?xml version=\"1.0\"?>\n<!-- The Pro part of the fast tier (see the public phpunit-fast.xml.dist). Written by bin/pro-split.php. -->\n"
            . "<phpunit bootstrap=\"../tests/bootstrap-fast.php\" colors=\"true\" cacheResult=\"false\">\n  <testsuites>\n";
        foreach ($fast as $name => $files) {
            $xml .= "    <testsuite name=\"{$name}\">\n";
            foreach ($files as $f) {
                $xml .= "      <file>{$f}</file>\n";
            }
            $xml .= "    </testsuite>\n";
        }
        $xml .= "  </testsuites>\n  <php>\n    <const name=\"WPMCP_TESTING\" value=\"true\"/>\n  </php>\n</phpunit>\n";
        if (! is_file($root . '/' . PRO_DIR . '/phpunit-fast.xml.dist')) {
            $writes[ PRO_DIR . '/phpunit-fast.xml.dist' ] = $xml;
        }
    }
    return ['writes' => $writes, 'dropped' => $dropped];
}

// ---------------------------------------------------------------------------
// Check: the public tree after the split names no Pro class.

/**
 * @return array{hard: array<string, list<string>>, soft: array<string, list<string>>, pro_files: int, pro_classes: int}
 */
function check(string $root): array
{
    $manifest = load_manifest($root);
    $plan = plan_quiet($root);
    $writes = $plan['writes'];
    $pro = pro_class_index($root, $manifest, $writes);

    $public = [];
    foreach (array_merge(['wpmcp.php', 'uninstall.php'], list_files($root, 'src')) as $path) {
        if (! str_ends_with($path, '.php') || ! is_file($root . '/' . $path) || is_pro_path($path, $manifest)) {
            continue;
        }
        $public[] = $path;
    }
    $hard = [];
    $soft = [];
    foreach ($public as $path) {
        $code = $writes[ $path ] ?? (string) file_get_contents($root . '/' . $path);
        foreach (scan_php($code)['refs'] as $ref => $kind) {
            if (! isset($pro[ $ref ])) {
                continue;
            }
            if ('hard' === $kind) {
                $hard[ $path ][] = $ref;
            } else {
                $soft[ $path ][] = $ref;
            }
        }
    }
    $pro_files = 0;
    foreach (array_merge(list_files($root, 'src'), list_files($root, 'assets')) as $path) {
        if (is_pro_path($path, $manifest)) {
            $pro_files++;
        }
    }
    return ['hard' => $hard, 'soft' => $soft, 'pro_files' => $pro_files, 'pro_classes' => count($pro)];
}

/** plan() without failing on an already-split Plugin.php. */
function plan_quiet(string $root): array
{
    return plan($root);
}

// ---------------------------------------------------------------------------
// Suggest: grow a manifest from seed paths, dropping every Pro file a public
// file still names, until nothing public names a Pro class.

function suggest(string $root, string $seeds_file): string
{
    $seeds = array_values(array_filter(array_map('trim', (array) file($seeds_file)), static fn (string $l): bool => '' !== $l && ! str_starts_with($l, '#')));
    $manifest = ['include' => [], 'exclude' => []];
    foreach ($seeds as $s) {
        if (str_starts_with($s, '!')) {
            $manifest['exclude'][] = substr($s, 1);
        } else {
            $manifest['include'][] = $s;
        }
    }
    $extract = extract_plugin_slots((string) file_get_contents($root . '/src/Plugin.php'));
    $virtual = [];
    foreach (list_files($root, 'src') as $path) {
        if (str_ends_with($path, '.php')) {
            $virtual[ $path ] = (string) file_get_contents($root . '/' . $path);
        }
    }
    if (null !== $extract) {
        $virtual['src/Plugin.php'] = $extract['plugin'];
        $virtual[ SLOTS_FILE ] = $extract['slots'];
    }
    $virtual['wpmcp.php'] = (string) file_get_contents($root . '/wpmcp.php');
    $decl = [];
    $refs = [];
    foreach ($virtual as $path => $code) {
        $scan = scan_php($code);
        foreach ($scan['declares'] as $c) {
            $decl[ $c ] = $path;
        }
        $refs[ $path ] = $scan['refs'];
    }
    $edges = [];
    $soft_in = [];
    foreach ($refs as $path => $r) {
        foreach ($r as $c => $kind) {
            if (isset($decl[ $c ]) && $decl[ $c ] !== $path && 'soft' === $kind) {
                $soft_in[ $decl[ $c ] ][ $path ] = true;
            }
        }
        foreach ($r as $c => $kind) {
            if (isset($decl[ $c ]) && $decl[ $c ] !== $path && 'hard' === $kind) {
                // Only hard references (code that loads the class) tie a file
                // to its caller; an import or a class-name string does not,
                // and `check` lists those for review.
                $edges[ $path ][ $decl[ $c ] ] = true;
            }
        }
    }
    $pro = [SLOTS_FILE => true];
    foreach (array_keys($virtual) as $path) {
        if (is_pro_path($path, $manifest)) {
            $pro[ $path ] = true;
        }
    }
    $dropped = [];
    $inbound = [];
    foreach ($edges as $from => $tos) {
        foreach (array_keys($tos) as $to) {
            $inbound[ $to ][ $from ] = true;
        }
    }
    // Grow to a fixed point (a public file every caller of which is Pro
    // joins Pro), then shrink to one (a Pro file a public file loads goes
    // back), and repeat until neither changes anything: growing first keeps
    // the result independent of the order files are visited in.
    for ($round = 0; $round < 50; $round++) {
        $changed = false;
        do {
            $grew = false;
            foreach (array_keys($virtual) as $path) {
                if (isset($pro[ $path ]) || 'wpmcp.php' === $path || ! str_starts_with($path, 'src/')) {
                    continue;
                }
                // A seed exclusion (`!path`) is public by decision.
                foreach ($manifest['exclude'] as $pattern) {
                    if (path_matches($path, $pattern)) {
                        continue 2;
                    }
                }
                $in = array_keys($inbound[ $path ] ?? []);
                if ([] === $in || [] !== array_filter($in, static fn (string $f): bool => ! isset($pro[ $f ]))) {
                    continue;
                }
                // A public file that names it by string or import keeps it public
                // unless it was asked for (a seed); check lists such names.
                $soft = array_keys($soft_in[ $path ] ?? []);
                if ([] !== array_filter($soft, static fn (string $f): bool => ! isset($pro[ $f ]))) {
                    continue;
                }
                $pro[ $path ] = true;
                $grew = $changed = true;
            }
        } while ($grew);
        do {
            $shrank = false;
            foreach ($edges as $from => $tos) {
                if (isset($pro[ $from ])) {
                    continue;
                }
                foreach (array_keys($tos) as $to) {
                    if (isset($pro[ $to ]) && SLOTS_FILE !== $to) {
                        unset($pro[ $to ]);
                        $dropped[ $to ] = $from;
                        $shrank = $changed = true;
                    }
                }
            }
        } while ($shrank);
        if (! $changed) {
            break;
        }
    }
    foreach (array_keys($pro) as $path) {
        unset($dropped[ $path ]);
    }
    unset($pro[ SLOTS_FILE ]);
    // Seeds that hold no PHP (a skill directory, assets) are kept as given.
    foreach ($manifest['include'] as $pattern) {
        $files = str_ends_with($pattern, '/') ? list_files($root, rtrim($pattern, '/')) : (is_file($root . '/' . $pattern) ? [$pattern] : []);
        if ([] !== $files && [] === array_filter($files, static fn (string $f): bool => str_ends_with($f, '.php'))) {
            $pro[ $pattern ] = true;
        }
    }
    $paths = array_keys($pro);
    sort($paths);
    $out = "# Pro-only paths (bin/pro-split.php). One repo-relative path per line, a\n"
        . "# directory ends in /. Each moves to pro/<path>. The free zip build fails\n"
        . "# when any of these is present. Regenerate with `php bin/pro-split.php suggest`\n"
        . "# and verify with `php bin/pro-split.php check`.\n";
    $out .= implode("\n", $paths) . "\n";
    if ([] !== $dropped) {
        ksort($dropped);
        $out .= "\n# Kept public because a public file still names them (needs a seam first):\n";
        foreach ($dropped as $path => $by) {
            $out .= "#   {$path}  <- {$by}\n";
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Main.

$args = [];
$cmd = $argv[1] ?? '';
foreach (array_slice($argv, 2) as $arg) {
    if (1 !== preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        fail("unknown argument {$arg}", 2);
    }
    $args[ $m[1] ] = $m[2] ?? true;
}
$root = rtrim(is_string($args['root'] ?? null) ? $args['root'] : dirname(__DIR__), '/');
if (! is_file($root . '/src/Plugin.php') && ! is_file($root . '/wpmcp.php')) {
    fail("{$root} is not a plugin checkout", 2);
}

switch ($cmd) {
    case 'suggest':
        if (! is_string($args['seeds'] ?? null)) {
            fail('suggest needs --seeds=FILE', 2);
        }
        fwrite(STDOUT, suggest($root, $args['seeds']));
        exit(0);

    case 'check':
        $result = check($root);
        $nh = array_sum(array_map('count', $result['hard']));
        $ns = array_sum(array_map('count', $result['soft']));
        foreach ($result['hard'] as $path => $refs) {
            fwrite(STDOUT, "HARD {$path}: " . implode(', ', array_unique($refs)) . "\n");
        }
        foreach ($result['soft'] as $path => $refs) {
            fwrite(STDOUT, "soft {$path}: " . implode(', ', array_unique($refs)) . "\n");
        }
        fwrite(STDOUT, sprintf("pro-split check: %d Pro files, %d Pro classes; public references: %d hard, %d soft (imports, strings)\n", $result['pro_files'], $result['pro_classes'], $nh, $ns));
        exit(0 === $nh && (! isset($args['strict']) || 0 === $ns) ? 0 : 1);

    case 'plan':
    case 'move':
        $plan = plan($root);
        $dry = 'plan' === $cmd || isset($args['dry-run']);
        foreach ($plan['notes'] as $note) {
            fwrite(STDOUT, "note: {$note}\n");
        }
        $moved = 0;
        foreach ($plan['moves'] as $from => $to) {
            if ($dry) {
                fwrite(STDOUT, "move  {$from} -> {$to}\n");
                $moved++;
                continue;
            }
            @mkdir(dirname($root . '/' . $to), 0777, true);
            if (isset($plan['writes'][ $to ])) {
                unlink($root . '/' . $from);
            } elseif (! rename($root . '/' . $from, $root . '/' . $to)) {
                fail("could not move {$from}");
            }
            $moved++;
        }
        foreach ($plan['writes'] as $path => $content) {
            if ($dry) {
                fwrite(STDOUT, "write {$path} (" . strlen($content) . " bytes)\n");
                continue;
            }
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, $content);
        }
        if (! $dry) {
            // Directories the moves emptied.
            foreach (['src', 'tests', 'assets'] as $dir) {
                if (! is_dir($root . '/' . $dir)) {
                    continue;
                }
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    if ($f->isDir() && [] === array_diff((array) scandir((string) $f), ['.', '..'])) {
                        rmdir((string) $f);
                    }
                }
            }
        }
        fwrite(STDOUT, sprintf("pro-split %s: %d moves, %d writes%s\n", $cmd, $moved, count($plan['writes']), $dry ? ' (dry run, nothing changed)' : ''));
        exit(0);

    default:
        fail('usage: php bin/pro-split.php check|plan|move|suggest [--root=DIR] [--dry-run] [--strict] [--seeds=FILE]', 2);
}
