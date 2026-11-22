<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Platform;

use PHPUnit\Framework\TestCase;
use WPMCP\Tests\Free\Platform\BuilderSlugGlueGuardTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the builder slug glue guard (tests/free/Platform/BuilderSlugGlueGuardTest):
 * the same two checks over every string literal in the Pro sources, and the
 * Builder_Design::BUILDERS list the slugs partly come from.
 */
final class BuilderSlugGlueGuardProTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    public function test_the_slug_list_is_found(): void
    {
        $design = Edition_Tree::pro_sources()['src/Tools/Builders/Builder_Design.php'] ?? null;
        $this->assertNotNull($design, 'Builder_Design.php is missing from the Pro sources.');
        $this->assertMatchesRegularExpression("/const BUILDERS = \\[[^\\]]*'visual-composer'/", (string) file_get_contents((string) $design));

        $slugs = BuilderSlugGlueGuardTest::slugs();
        $this->assertContains('elementor', $slugs);
        $this->assertContains('visual-composer', $slugs);
        $this->assertContains('classic', $slugs);
    }

    public function test_no_string_runs_a_builder_slug_into_the_next_word(): void
    {
        $found = BuilderSlugGlueGuardTest::glued(BuilderSlugGlueGuardTest::literals(Edition_Tree::pro_sources()));

        $this->assertSame([], $found, "Builder slugs glued to the next word (a text merge dropped a separator):\n" . implode("\n", $found));
    }

    public function test_no_string_lists_a_builder_slug_twice(): void
    {
        $found = BuilderSlugGlueGuardTest::repeated(BuilderSlugGlueGuardTest::literals(Edition_Tree::pro_sources()));

        $this->assertSame([], $found, "A builder list names a slug twice (a text merge repeated part of it):\n" . implode("\n", $found));
    }
}
