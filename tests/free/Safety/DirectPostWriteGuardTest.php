<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Tests\Support\Edition_Tree;

/**
 * Issue #440 guard: wp_update_post() and wp_trash_post() re-save a post's
 * whole row, so for a user without unfiltered_html kses rewrites the text
 * columns a call never meant to touch. Every write to an existing post goes
 * through WPMCP\Safety\Save_Filters, which filters only the columns the call
 * sets.
 *
 * This scans src/ for direct wp_update_post(), wp_insert_post() and
 * wp_trash_post() calls and fails on any that is not listed below with its
 * reason. Creating a new post is the usual legitimate case: every field of a
 * new row is new input, so all of it is filtered.
 *
 * This half scans the public sources (Edition_Tree::public_sources()), so it
 * runs the same in every tree. The Pro sources, and the allowlist entries
 * of Pro files, are in tests/pro/Safety/DirectPostWriteGuardProTest, which
 * reuses direct_calls() from here.
 */
class DirectPostWriteGuardTest extends \WP_UnitTestCase
{
    private const WATCHED = ['wp_update_post', 'wp_insert_post', 'wp_trash_post'];

    /**
     * File (relative to src/) => [function => number of direct calls], with
     * one line saying why each file may call it directly. Public files only;
     * Pro files are listed in the Pro half.
     *
     * @var array<string, array{calls: array<string, int>, reason: string}>
     */
    private const ALLOWED = [
        'Safety/Save_Filters.php'                     => [
            'calls'  => ['wp_update_post' => 1, 'wp_trash_post' => 1],
            'reason' => 'The helper itself: it lifts the filters of the columns a call leaves alone around these calls.',
        ],
        'Safety/Rollback_Service.php'                 => [
            'calls'  => ['wp_update_post' => 2, 'wp_trash_post' => 1, 'wp_insert_post' => 1],
            'reason' => 'Restores a snapshot row as stored; every call runs inside Save_Filters::without() with all post save filters lifted.',
        ],
        'Tools/Builders/Beaver_Builder_Page.php'      => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Beaver Builder saved template from a page node (issue #638); every field is new.',
        ],
        'Integrations/Thrive_Ultimatum_Pack.php'      => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Thrive Ultimatum campaign post (issue #828); every field is new. Its other writes are metas and the plugin\'s own tables.',
        ],
        'Integrations/Core_Fonts_Pack.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new font family or font face post (issue #712), as core\'s font endpoints do; every field is new.',
        ],
        'Integrations/Maps_Pack.php'                  => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new WP Store Locator store post (issue #907); every field is new. Updates and trashing go through Save_Filters.',
        ],
        'Integrations/CartFlows_Pack.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new CartFlows flow or step post (issue #662); every field is new. Changes to existing flows and steps are meta writes.',
        ],
        'Integrations/Cpt_Builder_Pack.php'           => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new MB Custom Post Types definition post (issue #710); every field is new. Updates go through Save_Filters.',
        ],
        'Integrations/Theme_Layouts_Pack.php'         => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new theme add-on layout post (issue #656); every field is new. Updates go through Save_Filters.',
        ],
        'Integrations/Event_Calendar_Writes.php'      => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new event, venue or organizer post for The Events Calendar or Modern Events Calendar (issue #677); every field is new. Updates go through Save_Filters.',
        ],
        'Tools/Builders/Breakdance_Page.php'          => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Breakdance Global Block or Oxygen Component from a page node (issues #634, #635); every field is new.',
        ],
        'Tools/SiteEditor/Site_Templates_Write.php'   => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the first customization of a template; every field is new.',
        ],
        'Tools/SiteEditor/Global_Styles.php'          => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the user global styles post; every field is new.',
        ],
        'Tools/Sync/Change_Set_Applier.php'           => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a post the target does not have yet; every field is new.',
        ],
        'Tools/Compose/Build_Page.php'                => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new page.',
        ],
        'Tools/Content/Create_Post.php'               => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new post.',
        ],
        'Tools/Content/Duplicate_Post.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the copy (or stage) of a post as a new row.',
        ],
        'Tools/ThemeBuilder/Template_Store.php'       => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new site part post.',
        ],
        'Tools/Export/Import_Content.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Imports a post as a new row.',
        ],
        'Memory/Memory_Store.php'                     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new memory entry.',
        ],
        'Integrations/LMS_Adapter.php'                => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new course, section or lesson post.',
        ],
        'Integrations/LMS_Thrive_Apprentice.php'      => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Thrive Apprentice lesson post.',
        ],
        'Integrations/LMS_LearnPress.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new LearnPress lesson or quiz post before placing it in a section row.',
        ],
        'Integrations/LMS_MasterStudy.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new MasterStudy LMS lesson or quiz post before placing it in a curriculum materials row (issue #1033).',
        ],
        'Integrations/Core_Patterns_Pack.php'         => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new synced pattern (wp_block) post.',
        ],
        'Integrations/BetterDocs_Pack.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new BetterDocs doc post.',
        ],
        'Integrations/Thrive_Ovation_Pack.php'        => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Thrive Ovation testimonial post (issue #829); every field is new. Updates go through Save_Filters.',
        ],
        'Integrations/Listings_Pack.php'              => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new WP Job Manager, GeoDirectory or Directorist listing post (issue #707).',
        ],
        'Integrations/Thirsty_Affiliates_Pack.php'    => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new ThirstyAffiliates link post.',
        ],
        'Integrations/Advanced_Ads_Pack.php'          => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Advanced Ads ad post.',
        ],
        'Integrations/Gamipress_Pack.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new GamiPress achievement post.',
        ],
        'Integrations/WPFunnels_Pack.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new, empty WPFunnels funnel post.',
        ],
        'Integrations/FooGallery_Pack.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new FooGallery gallery post.',
        ],
        'Integrations/Popup_Maker_Pack.php'           => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Popup Maker popup post.',
        ],
        'Integrations/MetaSlider_Store.php'           => [
            'calls'  => ['wp_insert_post' => 2],
            'reason' => 'Creates a new MetaSlider slider or slide post (issue #705); every field is new. Slide changes go through Save_Filters.',
        ],
        'Integrations/Modula_Store.php'               => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Modula gallery post (issue #705); every field is new. Image changes are meta writes.',
        ],
        'Integrations/Envira_Store.php'               => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Envira gallery post (issue #705); every field is new. Image changes are meta writes.',
        ],
        'Integrations/MemberPress_Pack.php'           => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new MemberPress membership or rule post.',
        ],
        'Integrations/EDD_Pack.php'                   => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Easy Digital Downloads download post.',
        ],
    ];

    /**
     * Direct calls to the watched functions, found with the tokenizer so
     * comments, strings, method calls and function definitions never count.
     *
     * @param array<string, string>|null $sources src/... path => absolute path (Edition_Tree); the public sources when null
     * @return array<string, array<string, int>> file (relative to src/) => [function => count]
     */
    public static function direct_calls(?array $sources = null): array
    {
        $found = [];
        foreach ($sources ?? Edition_Tree::public_sources() as $public => $absolute) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($absolute)),
                static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $token) {
                if (! is_array($token)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ! in_array($name, self::WATCHED, true)) {
                    continue;
                }
                if ('(' !== ($tokens[ $i + 1 ] ?? null)) {
                    continue;
                }
                $prev = $tokens[ $i - 1 ] ?? null;
                if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }
                $relative                    = substr($public, strlen('src/'));
                $found[ $relative ][ $name ] = ($found[ $relative ][ $name ] ?? 0) + 1;
            }
        }
        ksort($found);
        return $found;
    }

    public function test_the_scan_sees_the_known_direct_calls(): void
    {
        $found = self::direct_calls();
        $this->assertSame(['wp_update_post' => 1, 'wp_trash_post' => 1], $found['Safety/Save_Filters.php'] ?? null, 'The scan must find the calls inside Save_Filters, or it proves nothing.');
    }

    public function test_every_direct_post_write_is_on_the_documented_allowlist(): void
    {
        $unlisted = [];
        foreach (self::direct_calls() as $file => $calls) {
            foreach ($calls as $function => $count) {
                $allowed = self::ALLOWED[ $file ]['calls'][ $function ] ?? 0;
                if ($count > $allowed) {
                    $unlisted[] = sprintf('src/%s calls %s() directly %d time(s), %d allowed', $file, $function, $count, $allowed);
                }
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "A write to an existing post must go through WPMCP\\Safety\\Save_Filters (update_post, set_post_status, trash_post),\n"
            . "which filters only the columns it sets. If the call creates a new post, add it to ALLOWED with its reason.\n"
        );
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        $found = self::direct_calls();
        $stale = [];
        foreach (self::ALLOWED as $file => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "src/{$file} needs a reason.");
            foreach ($entry['calls'] as $function => $allowed) {
                $count = $found[ $file ][ $function ] ?? 0;
                if ($count !== $allowed) {
                    $stale[] = sprintf('src/%s: %s() allowed %d, found %d', $file, $function, $allowed, $count);
                }
            }
        }
        $this->assertSame([], $stale, 'Keep ALLOWED exact, so a removed call does not leave room for a new one.');
    }

    public function test_the_allowlist_names_only_public_files(): void
    {
        $pro = array_values(array_filter(array_keys(self::ALLOWED), static fn (string $file): bool => Edition_Tree::is_pro_path('src/' . $file)));
        $this->assertSame([], $pro, 'Allowlist entries of Pro files belong in tests/pro/Safety/DirectPostWriteGuardProTest.');
    }
}
