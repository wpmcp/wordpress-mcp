<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Taxonomy;

use WPMCP\Tools\Terms\Create_Term;
use WPMCP\Tools\Terms\Update_Term;

/**
 * Issue #1198: a parent on a non-hierarchical taxonomy is refused as "not
 * hierarchical", even when the parent id exists only in another taxonomy.
 */
final class TermParentRefusalTest extends \WP_UnitTestCase
{
    public function test_create_term_with_a_parent_on_a_tag_says_tags_are_not_hierarchical(): void
    {
        $category = self::factory()->category->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Taxonomy "post_tag" is not hierarchical, so a parent cannot be set.');
        (new Create_Term())->handle([ 'taxonomy' => 'post_tag', 'name' => 'Tag 1198', 'parent' => $category ]);
    }

    public function test_update_term_with_a_parent_on_a_tag_says_tags_are_not_hierarchical(): void
    {
        $category = self::factory()->category->create();
        $tag      = self::factory()->tag->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Taxonomy "post_tag" is not hierarchical, so a parent cannot be set.');
        (new Update_Term())->handle([ 'taxonomy' => 'post_tag', 'term_id' => $tag, 'parent' => $category ]);
    }

    public function test_a_missing_parent_on_a_category_still_says_it_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent term 999999 does not exist in "category".');
        (new Create_Term())->handle([ 'taxonomy' => 'category', 'name' => 'Cat 1198', 'parent' => 999999 ]);
    }
}
