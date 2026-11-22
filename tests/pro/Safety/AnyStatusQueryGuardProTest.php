<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Tests\Free\Safety\AnyStatusQueryGuardTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the issue #465 guard (tests/free/Safety/AnyStatusQueryGuardTest):
 * every any-status query in the Pro sources goes through Readable_Posts or
 * is listed below with its reason. Uses the public half's scanner, so both
 * halves flag exactly the same shapes.
 */
class AnyStatusQueryGuardProTest extends \WP_UnitTestCase
{
    /**
     * File (relative to src/) :: enclosing function => [count, reason], for
     * Pro files only.
     *
     * @var array<string, array{count: int, reason: string}>
     */
    private const ALLOWED = [
        'Tools/WooCommerce/Catalog/Woo_Write.php::variations_at_risk'      => [
            'count'  => 1,
            'reason' => 'Existence check before a product write: answers whether any variation exists, no row leaves.',
        ],
        'Pro/Chat/Conversation_Store.php::purge_for_user'                  => [
            'count'  => 1,
            'reason' => 'Author-scoped to the user being deleted; runs on user deletion, lists nothing.',
        ],
        'Pro/Chat/Conversation_Store.php::find_by_client_id'               => [
            'count'  => 1,
            'reason' => 'Author-scoped to the caller\'s own conversations, and the hit is re-checked for ownership.',
        ],
        'Pro/Chat/Conversation_Store.php::list_for_user'                   => [
            'count'  => 1,
            'reason' => 'Author-scoped to the caller\'s own conversations, and every row is re-checked for ownership.',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    /** @return array<string, int> "file::function" => count, for the Pro sources */
    private static function scan_src(): array
    {
        return AnyStatusQueryGuardTest::scan_sources(Edition_Tree::pro_sources());
    }

    public function test_every_any_status_query_is_filtered_or_on_the_documented_allowlist(): void
    {
        $unlisted = [];
        foreach (self::scan_src() as $site => $count) {
            $allowed = self::ALLOWED[ $site ]['count'] ?? 0;
            if ($count > $allowed) {
                $unlisted[] = sprintf('src/%s: %d unfiltered any-status quer%s, %d allowed', $site, $count, 1 === $count ? 'y' : 'ies', $allowed);
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "A query over every post status must go through WPMCP\\Tools\\Content\\Readable_Posts (query() or get_posts()),\n"
            . "which keeps the rows to what the caller may read. If it never lists rows to a caller who may not read them,\n"
            . "add it to ALLOWED with a one-line reason.\n"
        );
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        $found = self::scan_src();
        $stale = [];
        foreach (self::ALLOWED as $site => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "src/{$site} needs a reason.");
            $count = $found[ $site ] ?? 0;
            if ($count !== $entry['count']) {
                $stale[] = sprintf('src/%s: allowed %d, found %d', $site, $entry['count'], $count);
            }
        }
        $this->assertSame([], $stale, 'Keep ALLOWED exact, so a removed query does not leave room for a new one.');
    }

    public function test_the_allowlist_names_only_pro_files(): void
    {
        $public = [];
        foreach (array_keys(self::ALLOWED) as $site) {
            if (! Edition_Tree::is_pro_path('src/' . strstr($site, '::', true))) {
                $public[] = 'src/' . $site;
            }
        }
        $this->assertSame([], $public, 'Allowlist entries of public files belong in tests/free/Safety/AnyStatusQueryGuardTest.');
    }
}
