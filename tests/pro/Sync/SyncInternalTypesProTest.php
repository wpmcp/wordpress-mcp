<?php

namespace WPMCP\Tests\Pro\Sync;

use WPMCP\Tests\Free\Sync\SyncInternalTypesTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the issue #506 guard (tests/free/Sync/SyncInternalTypesTest):
 * every post type and meta key the Pro sources declare is either
 * deliberately syncable or deliberately kept out of change sets, and the
 * post types the public half lists as PRO_OWNED_POST_TYPES are really
 * declared here.
 */
class SyncInternalTypesProTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    public function test_the_scan_finds_the_known_stores(): void
    {
        $types = SyncInternalTypesTest::own_post_types(Edition_Tree::pro_sources());
        foreach (SyncInternalTypesTest::PRO_OWNED_POST_TYPES as $known) {
            $this->assertContains($known, $types, 'the source scan is not finding WP MCP post types, so the classification below proves nothing');
        }
    }

    public function test_every_wpmcp_post_type_is_deliberately_classified_for_sync(): void
    {
        foreach (SyncInternalTypesTest::own_post_types(Edition_Tree::pro_sources()) as $type) {
            SyncInternalTypesTest::assert_post_type_classified($this, $type);
        }
    }

    public function test_every_wpmcp_meta_key_is_deliberately_classified_for_sync(): void
    {
        $keys = SyncInternalTypesTest::own_meta_keys(Edition_Tree::pro_sources());
        $this->assertContains('_wpmcp_chat_messages', $keys, 'the meta scan is not finding WP MCP meta keys');

        foreach ($keys as $key) {
            SyncInternalTypesTest::assert_meta_key_classified($this, $key);
        }
    }
}
