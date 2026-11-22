<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\Content\Content_Guard;

/**
 * Issue #517: every post type WP MCP itself creates is deliberately
 * classified for the generic content tools, so a new store that holds
 * governance or private data cannot be left reachable through create-post,
 * update-post, the meta tools and the rest the way agent memory was.
 *
 * Modeled on the sync enumeration from issue #506 (SyncInternalTypesTest).
 *
 * This half scans the public sources (Edition_Tree::public_sources()). The
 * classification lists name every type, Pro ones included, since
 * Content_Guard is public code; the types only Pro code declares are listed
 * in PRO_OWNED_TYPES, and tests/pro/Content/ContentGuardInternalTypesProTest
 * finds them in the Pro sources.
 */
class ContentGuardInternalTypesTest extends \WP_UnitTestCase
{
    /**
     * Types the generic content tools may write on purpose. Their payload
     * lives in protected `_`-prefixed meta, which check_meta() refuses, and
     * their own builder tools own that payload.
     */
    public const GENERIC_ON_PURPOSE = [
        'wpmcp_block',
        'wpmcp_widget',
    ];

    /**
     * Types the generic tools may read but never write: theme-builder site
     * parts render on every matching page and have their own tools.
     */
    public const READ_ONLY = [
        'wpmcp_template',
    ];

    /**
     * Types that hold governance or private data, never reachable through
     * the generic tools at all: per-user chat conversations, and agent
     * memory (publishing or editing an entry would bypass the human
     * approval gate).
     */
    public const GOVERNANCE_OR_PRIVATE = [
        'wpmcp_chat_convo',
        'wpmcp_memory',
    ];

    /**
     * Types classified above that only Pro code declares (block and widget
     * specs, chat conversations). The public scan must not find them, and
     * the Pro half must, so this list stays exact.
     */
    public const PRO_OWNED_TYPES = [
        'wpmcp_block',
        'wpmcp_chat_convo',
        'wpmcp_widget',
    ];

    /** @var string[] types this test registered because nothing else had */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        // is_writable_post_type() also requires the type to be registered;
        // register any that this request has not, so the classification is
        // what is being tested rather than registration order.
        foreach (array_merge(self::own_post_types(), self::PRO_OWNED_TYPES) as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => false]);
                $this->registered[] = $type;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $type) {
            unregister_post_type($type);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $sources src/... path => absolute path (Edition_Tree)
     * @return string[] distinct matches of $pattern's first group across $sources
     */
    private static function scan(string $pattern, array $sources): array
    {
        $found = [];
        foreach ($sources as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source in a test.
            if (preg_match_all($pattern, (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $name) {
                    $found[ $name ] = true;
                }
            }
        }
        $names = array_keys($found);
        sort($names);
        return $names;
    }

    /**
     * WP MCP post types, from source. The runtime registry is not used: other
     * tests register throwaway wpmcp_* types that are not the plugin's.
     *
     * @param array<string, string>|null $sources src/... path => absolute path; the public sources when null
     * @return string[]
     */
    public static function own_post_types(?array $sources = null): array
    {
        $sources ??= Edition_Tree::public_sources();
        $types     = array_values(array_unique(array_merge(
            self::scan("/POST_TYPE\\s*=\\s*'(wpmcp_[a-z0-9_]+)'/", $sources),
            self::scan("/register_post_type\\(\\s*'(wpmcp_[a-z0-9_]+)'/", $sources)
        )));
        sort($types);
        return $types;
    }

    public function test_the_scan_finds_the_known_stores(): void
    {
        $types = self::own_post_types();
        foreach (array_diff(array_merge(self::GENERIC_ON_PURPOSE, self::READ_ONLY, self::GOVERNANCE_OR_PRIVATE), self::PRO_OWNED_TYPES) as $known) {
            $this->assertContains($known, $types, 'the source scan is not finding WP MCP post types, so the classification below proves nothing');
        }
        $this->assertSame([], array_values(array_intersect(self::PRO_OWNED_TYPES, $types)), 'PRO_OWNED_TYPES lists a type public code declares; drop it from the list');
        $this->assertSame([], array_values(array_diff(self::PRO_OWNED_TYPES, self::GENERIC_ON_PURPOSE, self::READ_ONLY, self::GOVERNANCE_OR_PRIVATE)), 'PRO_OWNED_TYPES lists a type with no classification');
    }

    public function test_every_wpmcp_post_type_is_deliberately_classified_for_the_content_tools(): void
    {
        foreach (array_merge(self::own_post_types(), self::PRO_OWNED_TYPES) as $type) {
            self::assert_classified($this, $type);
        }
    }

    /** One type's classification, and Content_Guard agreeing with it. */
    public static function assert_classified(\PHPUnit\Framework\TestCase $test, string $type): void
    {
        $generic = in_array($type, self::GENERIC_ON_PURPOSE, true);
        $read    = in_array($type, self::READ_ONLY, true);
        $closed  = in_array($type, self::GOVERNANCE_OR_PRIVATE, true);
        $test->assertSame(
            1,
            (int) $generic + (int) $read + (int) $closed,
            "WP MCP post type {$type} is not classified: add it to Content_Guard's internal lists (INTERNAL_TYPES for writes, PRIVATE_TYPES for reads), or to GENERIC_ON_PURPOSE here if the generic content tools are meant to reach it"
        );

        $test->assertSame($generic, Content_Guard::is_writable_post_type($type), "{$type}: generic writes do not match its classification");
        $test->assertSame(! $closed, Content_Guard::is_agent_readable_post_type($type), "{$type}: generic reads do not match its classification");
    }
}
