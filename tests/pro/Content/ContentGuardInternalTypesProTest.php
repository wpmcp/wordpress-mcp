<?php

namespace WPMCP\Tests\Pro\Content;

use WPMCP\Tests\Free\Content\ContentGuardInternalTypesTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the issue #517 guard (tests/free/Content/ContentGuardInternalTypesTest):
 * every post type the Pro sources declare is deliberately classified for
 * the generic content tools, and the types the public half lists as
 * PRO_OWNED_TYPES are really declared here.
 */
class ContentGuardInternalTypesProTest extends \WP_UnitTestCase
{
    /** @var string[] types this test registered because nothing else had */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
        foreach (self::own_post_types() as $type) {
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

    /** @return string[] */
    private static function own_post_types(): array
    {
        return ContentGuardInternalTypesTest::own_post_types(Edition_Tree::pro_sources());
    }

    public function test_the_scan_finds_the_known_stores(): void
    {
        $types = self::own_post_types();
        foreach (ContentGuardInternalTypesTest::PRO_OWNED_TYPES as $known) {
            $this->assertContains($known, $types, 'the source scan is not finding WP MCP post types, so the classification below proves nothing');
        }
    }

    public function test_every_wpmcp_post_type_is_deliberately_classified_for_the_content_tools(): void
    {
        foreach (self::own_post_types() as $type) {
            ContentGuardInternalTypesTest::assert_classified($this, $type);
        }
    }
}
