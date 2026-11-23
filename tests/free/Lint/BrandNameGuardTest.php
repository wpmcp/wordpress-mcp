<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Lint;

use PHPUnit\Framework\TestCase;

/**
 * The product is WPMCP, one word, from 2.0 (owner decision, 24 Nov 2026).
 * Fails when the old spaced name, "WP MCP", comes back in text a site owner
 * or an agent reads:
 *
 *  - the readmes: readme.txt, the flavor readmes, README.md and, when the
 *    submodule is checked out, pro/README.md;
 *  - the plugin headers of the three main files;
 *  - translatable strings: the string arguments of every gettext call in
 *    src/, pro/src/, the main files, uninstall.php and the flavor templates;
 *  - ability, tool and operation descriptions and the rest of the registered
 *    surface (schemas, opt-in labels, admin page titles), read from the
 *    golden capture of all three flavors in tests/support/golden/baseline.
 *    GoldenCaptureTest holds that capture equal to what the plugin
 *    registers, so a description with the old name fails one of the two.
 *
 * The only allowance is the historical changelog: the Changelog and Upgrade
 * Notice entries of released versions in a readme.txt (a v<version> git tag
 * exists, or the version is older than the Version header of wpmcp.php),
 * which describe what shipped. bin/brand-wpmcp.php applies the same rule
 * when it rewrites the old name, and lists this file in
 * bin/brand-wpmcp.exclude because it spells the old name on purpose.
 *
 * "WordPress MCP", "WPMCP", the WP-CLI command "wp mcp-stdio" and
 * identifiers such as WP_MCP_VERSION never match.
 */
final class BrandNameGuardTest extends TestCase
{
    /** The old name standing alone: not glued to a letter, digit or underscore. */
    private const OLD_NAME = '/(?<![A-Za-z0-9_])WP MCP(?![A-Za-z0-9_])/';

    private const FIX = 'The product is WPMCP, one word. Run php bin/brand-wpmcp.php, then regenerate the golden capture.';

    /** Readmes, relative to the repository root. Missing ones are skipped. */
    private const READMES = [
        'readme.txt',
        'README.md',
        'scripts/flavors/wporg/readme.txt',
        'scripts/flavors/woocommerce/readme.txt',
        'pro/README.md',
    ];

    /** The main plugin file of each flavor: its header is what the Plugins screen shows. */
    private const MAIN_FILES = [
        'wpmcp.php',
        'scripts/flavors/wporg/wpmcp.php',
        'scripts/flavors/woocommerce/safe-mcp-for-woocommerce.php',
    ];

    /** Roots whose PHP is scanned for gettext calls. Missing ones are skipped. */
    private const I18N_ROOTS = ['src', 'pro/src', 'wpmcp.php', 'uninstall.php', 'scripts/flavors'];

    private const GETTEXT = [
        '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop',
        'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x',
    ];

    private const SURFACE = 'tests/support/golden/baseline';

    public function test_the_readmes_use_the_one_word_name(): void
    {
        $hits = [];
        $read = 0;
        foreach (self::READMES as $relative) {
            $path = $this->root() . '/' . $relative;
            if (! is_file($path)) {
                continue;
            }
            $read++;
            $lines = explode("\n", (string) file_get_contents($path));
            $released = 'readme.txt' === strtolower(basename($relative)) ? $this->released_entry_lines($lines) : [];
            foreach ($lines as $index => $line) {
                if (! isset($released[$index]) && $this->has_old_name($line)) {
                    $hits[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $read, 'the readmes moved; update READMES');
        $this->assertSame([], $hits, self::FIX);
    }

    public function test_the_plugin_headers_use_the_one_word_name(): void
    {
        $hits = [];
        foreach (self::MAIN_FILES as $relative) {
            $contents = (string) file_get_contents($this->root() . '/' . $relative);
            // The first comment block, as get_file_data() reads it.
            $this->assertSame(1, preg_match('~/\*.*?\*/~s', $contents, $header, PREG_OFFSET_CAPTURE), $relative . ' has no header comment');
            $this->assertStringContainsString('Plugin Name:', $header[0][0], $relative);
            $first = substr_count($contents, "\n", 0, $header[0][1]);
            foreach (explode("\n", $header[0][0]) as $index => $line) {
                if ($this->has_old_name($line)) {
                    $hits[] = $relative . ':' . ($first + $index + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, self::FIX);
    }

    public function test_translatable_strings_use_the_one_word_name(): void
    {
        $hits = [];
        $calls = 0;
        foreach ($this->php_files() as $relative => $path) {
            foreach ($this->gettext_strings((string) file_get_contents($path)) as [$line, $text]) {
                $calls++;
                if ($this->has_old_name($text)) {
                    $hits[] = $relative . ':' . $line . ': ' . $text;
                }
            }
        }

        $this->assertGreaterThan(500, $calls, 'the gettext scan found almost no calls, so it proves nothing');
        $this->assertSame([], $hits, self::FIX);
    }

    public function test_the_registered_surface_uses_the_one_word_name(): void
    {
        $files = glob($this->root() . '/' . self::SURFACE . '/*.json');
        $this->assertNotEmpty($files, 'no golden capture under ' . self::SURFACE);

        $hits = [];
        $descriptions = 0;
        foreach ((array) $files as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
                $descriptions += substr_count($line, '"description"');
                if ($this->has_old_name($line)) {
                    $hits[] = self::SURFACE . '/' . basename($path) . ':' . ($index + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertGreaterThan(1000, $descriptions, 'the golden capture holds almost no descriptions, so it proves nothing');
        $this->assertSame([], $hits, self::FIX);
    }

    public function test_the_matcher_finds_only_the_old_spaced_name(): void
    {
        foreach (['WP MCP', "WP MCP's own table", 'needs a WP MCP Pro license', '(WP MCP)', 'WP MCP: Abilities', 'Restored by a WP MCP rollback'] as $line) {
            $this->assertTrue($this->has_old_name($line), 'not caught: ' . $line);
        }
        foreach (['WPMCP', "WPMCP's own table", 'WPMCP Pro', 'WordPress MCP server', 'wp mcp-stdio serve --user=admin', 'WP_MCP_VERSION', 'wp_mcp', 'wp-mcp-for-woocommerce', 'MCP server'] as $line) {
            $this->assertFalse($this->has_old_name($line), 'flagged: ' . $line);
        }
    }

    public function test_only_the_entries_of_released_versions_keep_the_old_name(): void
    {
        $readme = [
            '=== WP MCP - Title ===',
            '== Description ==',
            'WP MCP does things.',
            '== Changelog ==',
            '= 999.0.0 =',
            '* WP MCP, not released yet.',
            '= 1.0.0 =',
            '* WP MCP 1.0 shipped.',
            '== Upgrade Notice ==',
            '= 999.0.0 =',
            'Not released: WP MCP.',
            '= 1.0.0 =',
            'Released: WP MCP.',
            '== Something Else ==',
            'WP MCP after the changelog.',
        ];
        $released = $this->released_entry_lines($readme);

        $flagged = [];
        foreach ($readme as $index => $line) {
            if (! isset($released[$index]) && $this->has_old_name($line)) {
                $flagged[] = $index;
            }
        }

        $this->assertSame([0, 2, 5, 10, 14], $flagged);
    }

    public function test_the_gettext_scan_reads_calls_and_skips_everything_else(): void
    {
        $php = <<<'PHP'
<?php
// WP MCP in a comment.
$a = __('WP MCP one', 'wpmcp');
esc_html_e("WP MCP two", 'wpmcp');
$b = \esc_attr__('WP MCP three', 'wpmcp');
$c = sprintf(_n('%d WP MCP site', '%d WP MCP sites', $n, 'wpmcp'), $n);
$d = 'WP MCP, not translatable';
$e = $object->__('WP MCP, a method');
function __x() { return 'WP MCP'; }
PHP;

        $texts = array_map(static fn (array $row): string => $row[1], $this->gettext_strings($php));

        $this->assertSame(
            ["WP MCP one\nwpmcp", "WP MCP two\nwpmcp", "WP MCP three\nwpmcp", "%d WP MCP site\n%d WP MCP sites\nwpmcp"],
            $texts
        );
    }

    private function has_old_name(string $text): bool
    {
        return 1 === preg_match(self::OLD_NAME, $text);
    }

    /**
     * The lines of a readme.txt inside the Changelog or Upgrade Notice entry
     * of a released version, the "= x.y.z =" line included.
     *
     * @param list<string> $lines
     * @return array<int, true> 0-based line index => true
     */
    private function released_entry_lines(array $lines): array
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
                $inside = $this->is_released(trim($m[1]));
            }
            if ($inside) {
                $out[$index] = true;
            }
        }

        return $out;
    }

    private function is_released(string $version): bool
    {
        static $tags = null;
        static $header = false;

        if (1 !== preg_match('/^\d+(?:\.\d+)*$/', $version)) {
            return false;
        }
        if (null === $tags) {
            $tags = [];
            $listing = shell_exec('git -C ' . escapeshellarg($this->root()) . ' tag -l 2>/dev/null');
            foreach (explode("\n", is_string($listing) ? $listing : '') as $tag) {
                if (1 === preg_match('/^v?(\d+(?:\.\d+)*)$/', trim($tag), $m)) {
                    $tags[$m[1]] = true;
                }
            }
        }
        if (false === $header) {
            $head = (string) file_get_contents($this->root() . '/wpmcp.php', false, null, 0, 8192);
            $header = 1 === preg_match('/^[ \t\/*#@]*Version:\s*(\d+(?:\.\d+)*)\s*$/mi', $head, $m) ? $m[1] : null;
        }

        return isset($tags[$version]) || (is_string($header) && version_compare($version, $header, '<'));
    }

    /**
     * The string arguments of every gettext call in a PHP source, one row per
     * call: [line, the call's string literals joined by newlines].
     *
     * @return list<array{0: int, 1: string}>
     */
    private function gettext_strings(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $functions = array_flip(self::GETTEXT);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ! isset($functions[ltrim($token[1], '\\')])) {
                continue;
            }
            $before = $this->significant($tokens, $i, -1);
            if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $open = $this->significant_index($tokens, $i, 1);
            if (null === $open || '(' !== $tokens[$open]) {
                continue;
            }

            $depth = 0;
            $texts = [];
            for ($j = $open; $j < $count; $j++) {
                $inner = $tokens[$j];
                if ('(' === $inner) {
                    $depth++;
                } elseif (')' === $inner && 0 === --$depth) {
                    break;
                } elseif (is_array($inner) && T_CONSTANT_ENCAPSED_STRING === $inner[0]) {
                    $texts[] = stripcslashes(substr($inner[1], 1, -1));
                } elseif (is_array($inner) && T_ENCAPSED_AND_WHITESPACE === $inner[0]) {
                    $texts[] = $inner[1];
                }
            }
            if ([] !== $texts) {
                $rows[] = [$token[2], implode("\n", $texts)];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed the next or previous token that is not whitespace or a comment
     */
    private function significant(array $tokens, int $from, int $step): mixed
    {
        $index = $this->significant_index($tokens, $from, $step);

        return null === $index ? null : $tokens[$index];
    }

    /** @param array<int, mixed> $tokens */
    private function significant_index(array $tokens, int $from, int $step): ?int
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<string, string> relative path => absolute path, for every PHP file under I18N_ROOTS */
    private function php_files(): array
    {
        $root = $this->root();
        $out = [];
        foreach (self::I18N_ROOTS as $entry) {
            $path = $root . '/' . $entry;
            if (is_file($path)) {
                $out[$entry] = $path;
                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                    $out[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($out);

        return $out;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
