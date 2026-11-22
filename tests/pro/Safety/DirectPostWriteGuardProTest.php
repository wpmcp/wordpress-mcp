<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Tests\Free\Safety\DirectPostWriteGuardTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the issue #440 guard (tests/free/Safety/DirectPostWriteGuardTest):
 * every direct wp_update_post(), wp_insert_post() and wp_trash_post() call
 * in the Pro sources is listed below with its reason. Uses the public
 * half's scanner.
 */
class DirectPostWriteGuardProTest extends \WP_UnitTestCase
{
    /**
     * Pro file (relative to src/) => [function => number of direct calls],
     * with one line saying why each file may call it directly.
     *
     * @var array<string, array{calls: array<string, int>, reason: string}>
     */
    private const ALLOWED = [
        'Tools/Builders/Avada_Library.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Avada library post from a page element (issue #637); every field is new.',
        ],
        'Tools/Builders/Flatsome_Blocks.php'          => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new UX Block (blocks post) from a page element or page (issue #849); every field is new.',
        ],
        'Tools/Builders/Bricks_Page.php'              => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Bricks section template from a page element (issue #633); every field is new.',
        ],
        'Tools/WidgetBuilder/Widget_Spec_Store.php'   => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new widget spec post.',
        ],
        'Tools/BlockBuilder/Block_Spec_Store.php'     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new block spec post.',
        ],
        'Tools/Elementor/Create_Code_Snippet.php'     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new code snippet post.',
        ],
        'Pro/Chat/Conversation_Store.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new chat conversation post.',
        ],
        'Tools/Builders/Oxygen6_Store.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Oxygen 6 page, template, header, footer or Component for create_page, save_template and import (issue #805); every field is new.',
        ],
        'Tools/Elementor/Elementor_Template_Data.php' => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Elementor template post.',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    /** @return array<string, array<string, int>> */
    private static function direct_calls(): array
    {
        return DirectPostWriteGuardTest::direct_calls(Edition_Tree::pro_sources());
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

    public function test_the_allowlist_names_only_pro_files(): void
    {
        $public = array_values(array_filter(array_keys(self::ALLOWED), static fn (string $file): bool => ! Edition_Tree::is_pro_path('src/' . $file)));
        $this->assertSame([], $public, 'Allowlist entries of public files belong in tests/free/Safety/DirectPostWriteGuardTest.');
    }
}
