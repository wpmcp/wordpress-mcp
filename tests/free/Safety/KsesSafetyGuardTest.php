<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Tests\Support\Edition_Tree;

/**
 * Issue #1243 guard: a write that stores markup in post_content for a user
 * without unfiltered_html has kses run over it, which can rewrite the live
 * page while the write reports success. Every source file that writes a
 * post row with a post_content column either runs WPMCP\Safety\Kses_Safety
 * (or the packs' Kses_Check) before the write, or is listed below with
 * the reason it needs no check and the test that shows it.
 *
 * A writer is a file with a wp_insert_post(), wp_update_post() or
 * Save_Filters::update_post() call that names 'post_content'. This half
 * scans the public sources; the Pro half is
 * tests/pro/Safety/KsesSafetyGuardProTest, which reuses writers() from here.
 */
class KsesSafetyGuardTest extends \WP_UnitTestCase
{
    /**
     * Public file (relative to src/) => why it needs no Kses_Safety check.
     *
     * @var array<string, string>
     */
    public const RECORDED = [
        'Tools/SiteEditor/Global_Styles.php'        => 'The styles are JSON, not markup: its verify step compares the stored document with the planned one and rolls back with an error when the save\'s filters removed a value (GlobalStylesTest).',
        'Integrations/Event_Calendar_Writes.php'    => 'The shared insert and update helpers of the event packs; their callers (The_Events_Calendar_Writes, Modern_Events_Calendar_Writes) report what kses removes (PacksKsesTest).',
        'Integrations/I18n_Page_Text.php'           => 'Writes the page text I18n_Pack::apply_page_text() planned; that op reports what kses removes (PacksKsesTest).',
        'Safety/Save_Filters.php'                   => 'The write helper itself; its callers run the check before they call it.',
        'Integrations/CartFlows_Pack.php'           => 'Creates flow and step posts with an empty post_content; their settings are post meta (PacksKsesTest).',
        'Memory/Memory_Store.php'                   => 'Agent memory entries are plain text: Memory_Entry::clean_text() strips every tag before the write, so kses has no markup left to remove (MorePacksKsesTest).',
        'Tools/ThemeBuilder/Template_Store.php'     => 'Runs wp_kses_post over template content for every user before the write, so the save has nothing left to change (BuilderAndSiteKsesTest).',
    ];

    /**
     * Files that write a post row naming post_content, and whether each
     * references Kses_Safety. Found with the tokenizer, so comments never
     * count.
     *
     * @param array<string, string>|null $sources src/... path => absolute path (Edition_Tree); the public sources when null
     * @return array<string, bool> file (relative to src/) => references Kses_Safety
     */
    public static function writers(?array $sources = null): array
    {
        $found = [];
        foreach ($sources ?? Edition_Tree::public_sources() as $public => $absolute) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($absolute)),
                static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            $writes  = false;
            $content = false;
            $guarded = false;
            foreach ($tokens as $i => $token) {
                if (! is_array($token)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (T_CONSTANT_ENCAPSED_STRING === $token[0] && in_array($token[1], ["'post_content'", '"post_content"'], true)) {
                    $content = true;
                }
                if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && (str_ends_with($name, 'Kses_Safety') || str_ends_with($name, 'Kses_Check'))) {
                    $guarded = true;
                }
                if ('(' !== ($tokens[ $i + 1 ] ?? null) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $prev = $tokens[ $i - 1 ] ?? null;
                if (in_array($name, ['wp_insert_post', 'wp_update_post'], true)) {
                    if (! is_array($prev) || ! in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                        $writes = true;
                    }
                } elseif ('update_post' === $name && is_array($prev) && T_DOUBLE_COLON === $prev[0]) {
                    $class = $tokens[ $i - 2 ] ?? null;
                    if (is_array($class) && str_ends_with(ltrim($class[1], '\\'), 'Save_Filters')) {
                        $writes = true;
                    }
                }
            }
            if ($writes && $content) {
                $found[ substr($public, strlen('src/')) ] = $guarded;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * @param array<string, bool>   $writers
     * @param array<string, string> $recorded
     * @return list<string>
     */
    public static function unchecked(array $writers, array $recorded): array
    {
        $out = [];
        foreach ($writers as $file => $guarded) {
            if (! $guarded && ! isset($recorded[ $file ])) {
                $out[] = "src/{$file}";
            }
        }

        return $out;
    }

    /**
     * @param array<string, bool>   $writers
     * @param array<string, string> $recorded
     * @return list<string>
     */
    public static function stale(array $writers, array $recorded): array
    {
        $out = [];
        foreach (array_keys($recorded) as $file) {
            if (! array_key_exists($file, $writers) || $writers[ $file ]) {
                $out[] = "src/{$file}";
            }
        }

        return $out;
    }

    public function test_the_scan_sees_a_known_writer_and_a_known_check(): void
    {
        $found = self::writers();
        $this->assertArrayHasKey('Tools/Update_Blocks.php', $found, 'The scan must find update-blocks, or it proves nothing.');
        $this->assertArrayHasKey('Safety/Save_Filters.php', $found);
    }

    public function test_every_post_content_writer_runs_kses_safety_or_is_recorded(): void
    {
        $this->assertSame(
            [],
            self::unchecked(self::writers(), self::RECORDED),
            "A write that stores post_content must refuse markup kses would rewrite for a user without unfiltered_html:\n"
            . "run WPMCP\\Safety\\Kses_Safety before it. If kses cannot change what it stores, add it to RECORDED with the reason and a test.\n"
        );
    }

    public function test_the_record_has_no_stale_entries(): void
    {
        foreach (self::RECORDED as $file => $reason) {
            $this->assertNotSame('', trim($reason), "src/{$file} needs a reason.");
            $this->assertFalse(Edition_Tree::is_pro_path("src/{$file}"), "src/{$file} is a Pro file; list it in the Pro half.");
        }
        $this->assertSame([], self::stale(self::writers(), self::RECORDED), 'Drop entries for files that no longer write post_content or now run Kses_Safety.');
    }
}
