<?php

namespace WPMCP\Tests\Free\Lint;

use PHPUnit\Framework\TestCase;

/**
 * Keeps overclaims about snapshots, undo and safety out of shipped
 * user-facing text: admin screens, tool and skill descriptions, the .mcpb
 * manifest, the readmes (main and per flavor), the prompts, the .pot, the
 * docs/ tree, the plugin header and the Pro sources when the submodule is
 * checked out.
 *
 * Not every write is snapshotted (some are refused, some report that undo is
 * partial, some are not reversible at all), so the copy says "a snapshot
 * before each supported write" and never "every write", "undo anything",
 * "roll back anything" or "the only".
 *
 * PHP, JS and CSS comment lines are skipped (they describe code, not the
 * product), except plugin header lines such as " * Description:", which
 * WordPress shows to the site owner.
 */
class OverclaimWordingGuardTest extends TestCase
{
    /** Case-insensitive patterns, one per kind of overclaim. */
    private const PATTERNS = [
        '/\b(?:undo(?:es)?|roll(?:s)? ?back|revert(?:s)?) (?:anything|everything)\b/i',
        '/\bsnapshot(?:s|ted)? (?:before|of|for) (?:every|all|any) (?:write|change|edit|mutation)s?\b/i',
        '/\bsnapshots (?:every|all|any) (?:write|change|edit|mutation)s?\b/i',
        '/\bevery (?:write|change|edit|mutation)s?(?: here)?(?: (?:is|are|gets|can be|was))? (?:snapshotted|undoable|reversible|restorable|rolled back|undone|reverted)\b/i',
        '/\b(?:all|every|any) (?:write|change|edit|mutation)s? (?:is |are |can be )?(?:fully )?(?:reversible|undoable|restorable)\b/i',
        '/\bmakes every change reversible\b/i',
        '/\balways (?:restorable|reversible|undoable)\b/i',
        '/\b100 ?% (?:safe|reversible|undoable|restorable|recoverable)\b/i',
        '/\bthe only (?:wordpress )?(?:mcp (?:server|plugin)|mcp with|plugin (?:that|with)|server (?:that|with)|safe|undoable)\b/i',
        '/\b(?:can(?:\'|\x{2019})?t|cannot|can not|never) (?:wreck|ruin|destroy)\b/iu',
        '/\bphysically (?:can(?:\'|\x{2019})?t|cannot|can not|unable)\b/iu',
        '/\bevery (?:write|change|edit|mutation)s? (?:takes|gets|creates|makes) a snapshot\b/i',
        '/\bevery (?:write|change|edit|mutation) snapshots\b/i',
        '/\bevery one (?:is|gets) snapshotted\b/i',
        '/\b(?:snapshot|before-image)s? (?:ahead of|in front of) (?:every|all|any) (?:write|change|edit|mutation)s?\b/i',
        '/\b(?:any|every) change can be (?:rolled back|undone|reverted|restored)\b/i',
        '/\broll (?:any|every) change back\b/i',
        '/\brestore any (?:change|snapshot|of them)\b/i',
        '/\beverything (?:is )?snapshotted\b/i',
        '/\bevery (?:mutating|write|writing) tool snapshots\b/i',
    ];

    /** Roots scanned, relative to the repository root. Missing ones are skipped. */
    private const ROOTS = [
        'src',
        'pro/src',
        'assets',
        'prompts',
        'languages',
        'scripts/flavors',
        'readme.txt',
        'README.md',
        'docs',
        'wpmcp.php',
    ];

    /** Build tooling under a scanned root that never ships as text a user reads. */
    private const SKIP_FILES = [
        'scripts/flavors/wporg/strip.php',
    ];

    private const EXTENSIONS = ['php', 'js', 'json', 'md', 'txt', 'html', 'pot', 'css'];

    public function test_no_shipped_user_facing_text_overclaims_snapshots_or_undo(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach ($this->files($root) as $relative => $path) {
            $code  = in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'js', 'css'], true);
            $lines = explode("\n", (string) file_get_contents($path));
            foreach ($lines as $index => $line) {
                if ($code && $this->is_comment($line)) {
                    continue;
                }
                if (null !== $this->match($line)) {
                    $hits[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            'Overclaiming copy. Say "a snapshot before each supported write" and "undo is free", never "every write", "undo anything" or "the only".'
        );
    }

    public function test_the_patterns_catch_the_known_overclaims_and_pass_the_honest_wording(): void
    {
        $bad = [
            'Every write is snapshotted first; review and roll back anything from the wpmcp History screen.',
            'MCP server for WordPress with a snapshot before every write and one-click rollback.',
            'Session rollback: undo everything an agent did in one conversation',
            'How wpmcp snapshots every mutation before it runs',
            'with every change undoable.',
            'Every change can be rolled back exactly.',
            'This release makes every change reversible across all of them.',
            'The only WordPress MCP with undo.',
            'Your content is always restorable.',
            "AI builds and edits your WordPress site, and physically can't wreck it.",
            'AI builds and edits your WordPress site, and physically cannot wreck it.',
            'Every write takes a snapshot first, so any change can be rolled back.',
            'so you can roll any change back from the History screen.',
            'Every write is snapshotted: `rollback-operation` deletes a created booking.',
            'Every write snapshots the whole subscription, its schedule included.',
            'and every one is snapshotted so rollback undoes it.',
            'wpmcp writes a before-image ahead of every mutation.',
            'Check the History screen and restore any change with one click.',
            'Check the History screen to see snapshots accumulate; restore any of them with one click.',
            'You can restore any snapshot, or roll back an entire agent session.',
            'Everything snapshotted, logged, and reversible',
            'Every mutating tool snapshots the affected data first.',
        ];
        foreach ($bad as $line) {
            $this->assertNotNull($this->match($line), 'Not caught: ' . $line);
        }

        $good = [
            'Before each supported write, wpmcp saves a snapshot. Review and restore changes from the wpmcp History screen.',
            'MCP server for WordPress with a snapshot before each supported write and one-click rollback.',
            'rollback-session with this session_id restores every changed row.',
            'Skipped, because the snapshot could not restore everything deleting them changes.',
            'unsubscribe it (status: unsubscribed, the only status a write sets)',
            'and undo is free.',
            'on stores where the only MCP adapter loaded is the older copy',
            'AI builds and edits your WordPress site, with a snapshot before each supported write.',
            'Each supported write is snapshotted: `rollback-operation` deletes a created booking.',
            'Use one session_id for every write in this task.',
            'the deny rule would deny every write on the site.',
            'The History screen lists the snapshot taken before each supported write, with one-click restore.',
            'History gives one-click restore, and you can roll back the supported writes of an entire agent session.',
        ];
        foreach ($good as $line) {
            $this->assertNull($this->match($line), 'Flagged honest text: ' . $line);
        }
    }

    private function match(string $line): ?string
    {
        foreach (self::PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $line)) {
                return $pattern;
            }
        }

        return null;
    }

    private function is_comment(string $line): bool
    {
        if (1 === preg_match('/^\s*\*\s*(?:Plugin Name|Description):/', $line)) {
            return false; // Plugin header: shown on the Plugins screen.
        }

        return 1 === preg_match('~^\s*(?:\*|//|/\*|#)~', $line);
    }

    /** @return array<string,string> relative path => absolute path */
    private function files(string $root): array
    {
        $out = [];
        foreach (self::ROOTS as $entry) {
            $path = $root . '/' . $entry;
            if (is_file($path)) {
                $out[$entry] = $path;
                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (in_array($relative, self::SKIP_FILES, true) || str_contains($relative, '.min.')) {
                    continue;
                }
                $out[$relative] = $file->getPathname();
            }
        }
        ksort($out);

        return $out;
    }
}
