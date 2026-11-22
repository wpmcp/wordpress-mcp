<?php

namespace WPMCP\Tests\Pro\Skills;

use WPMCP\Tests\Free\Skills\SkillLintTest;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * The Pro half of the bundled skill lint (issue #850,
 * tests/free/Skills/SkillLintTest): the Pro skills name only registered
 * tools, and only operations the whole surface (public and Pro handlers)
 * answers.
 */
class SkillLintProTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    /** @return array<string, string> slug => raw document */
    private static function pro_skills(): array
    {
        return SkillLintTest::bundled(Edition_Tree::pro_sources('md'));
    }

    public function test_the_scan_finds_the_pro_skills(): void
    {
        $this->assertArrayHasKey('wpmcp-elementor-editing', self::pro_skills(), 'The scan is not finding the Pro skills, so the checks below prove nothing.');
        $this->assertSame(SkillLintTest::PRO_OWNED_SKILLS, array_keys(self::pro_skills()), 'SkillLintTest::PRO_OWNED_SKILLS must list exactly the Pro skills.');
    }

    public function test_every_tool_a_bundled_skill_names_is_registered(): void
    {
        $this->assertSame([], SkillLintTest::tool_problems(self::pro_skills()), 'A bundled skill names a tool that does not exist.');
    }

    public function test_every_operation_a_bundled_skill_names_exists(): void
    {
        $slugs = array_fill_keys(array_merge(array_keys(SkillLintTest::bundled()), array_keys(self::pro_skills())), true);
        $bad   = SkillLintTest::operation_problems($this, self::pro_skills(), SkillLintTest::operations(false), $slugs);

        $this->assertSame([], $bad, 'A bundled skill names an operation that does not exist.');
    }
}
