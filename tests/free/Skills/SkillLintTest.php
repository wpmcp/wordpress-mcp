<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Pro\Gate;
use WPMCP\Skills\Skill_Library;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * Bundled skills cannot drift from the tool surface (issue #850): every tool
 * a bundled SKILL.md names is a registered ability, every operation it names
 * (in backticks, or as a `requires_any` entry) is an operation some
 * dispatcher pair really answers, and the playbooks stay short.
 *
 * This half covers the public bundled skills, and holds them to the public
 * operations (those answered by a handler class that is public code), so
 * the free build never ships a skill naming an operation it lacks. The Pro
 * skills (under src/Skills/library before the split, pro/src/Skills/library
 * after) are checked against the whole surface by
 * tests/pro/Skills/SkillLintProTest.
 */
class SkillLintTest extends \WP_UnitTestCase
{
    /** Integration playbooks are kept well under the 64 KB serving cap. */
    private const MAX_SKILL_BYTES = 12288;

    /**
     * Bundled skills whose files are Pro (they ship only with the Pro code)
     * that public skills still point at by slug, such as the integrations
     * router. The Pro half checks that these are exactly the Pro skills.
     */
    public const PRO_OWNED_SKILLS = [
        'wpmcp-elementor-editing',
    ];

    /** @var array<string, array<string, array<string, true>>> scope => ability => op names, every tier. */
    private static array $operations = [];

    /** Whether a handler class is Pro code (a Pro path, or anything under pro/). */
    private static function is_pro_class(object $handler): bool
    {
        $file = str_replace('\\', '/', (string) (new \ReflectionClass($handler))->getFileName());
        $root = Edition_Tree::root() . '/';
        if (! str_starts_with($file, $root)) {
            return false;
        }
        return Edition_Tree::is_pro_path(substr($file, strlen($root)));
    }

    /**
     * @param bool $public_only only abilities whose handler class is public code
     * @return array<string, array<string, true>>
     */
    public static function operations(bool $public_only = true): array
    {
        $scope = $public_only ? 'public' : 'all';
        if (isset(self::$operations[ $scope ])) {
            return self::$operations[ $scope ];
        }

        $operations = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $handler = $ability->handler;
            if (! is_array($handler) || ! isset($handler[0]) || ! is_object($handler[0]) || ! method_exists($handler[0], 'catalog')) {
                continue;
            }
            if ($public_only && self::is_pro_class($handler[0])) {
                continue;
            }
            Gate::set_pro_for_tests(true);
            try {
                $catalog = $handler[0]->catalog();
            } finally {
                Gate::set_pro_for_tests(null);
            }
            foreach ($catalog['operations'] as $op) {
                $operations[ $ability->name ][ (string) $op['name'] ] = true;
            }
            $operations[ $ability->name ]['list-operations'] = true;
        }

        return self::$operations[ $scope ] = $operations;
    }

    /**
     * @param array<string, string>|null $sources src/... path => absolute path of .md files (Edition_Tree); the public ones when null
     * @return array<string, string> slug => raw document, every bundled skill among $sources
     */
    public static function bundled(?array $sources = null): array
    {
        $prefix = 'src/Skills/library/';
        $docs   = [];
        foreach ($sources ?? Edition_Tree::public_sources('md') as $public => $file) {
            if (str_starts_with($public, $prefix) && str_ends_with($public, '/SKILL.md')) {
                $slug = substr($public, strlen($prefix), -strlen('/SKILL.md'));
                if (substr_count($slug, '/') <= 1) {
                    $docs[ $slug ] = (string) file_get_contents($file);
                }
            }
        }
        ksort($docs);

        return $docs;
    }

    /**
     * Problems with the operations the given skills name.
     *
     * @param array<string, string>               $skills     slug => raw document
     * @param array<string, array<string, true>>  $operations ability => op names
     * @param array<string, true>                 $slugs      every bundled skill slug a skill may point at
     * @return string[]
     */
    public static function operation_problems(\PHPUnit\Framework\TestCase $test, array $skills, array $operations, array $slugs): array
    {
        $all_ops = [];
        foreach ($operations as $ops) {
            $all_ops += $ops;
        }

        $bad = [];
        foreach ($skills as $slug => $raw) {
            $parsed = Skill_Library::parse($raw);
            $test->assertNotNull($parsed, $slug . ' has no frontmatter');

            foreach ((array) ($parsed['frontmatter']['requires_any'] ?? []) as $entry) {
                if (false === strpos((string) $entry, '#')) {
                    continue;
                }
                [ $ability, $op ] = explode('#', (string) $entry, 2);
                if (! isset($operations[ $ability ][ $op ])) {
                    $bad[] = $slug . ' requires_any ' . $entry . ', which ' . $ability . ' does not answer';
                }
            }

            preg_match_all('~`([a-z0-9]+(?:-[a-z0-9]+)+(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?)`~', $parsed['body'], $m);
            foreach (array_unique($m[1]) as $token) {
                if (! isset($all_ops[ $token ]) && ! isset($slugs[ $token ])) {
                    $bad[] = $slug . ' names `' . $token . '`, which is neither an operation nor a bundled skill';
                }
            }
        }

        return $bad;
    }

    /**
     * Tools the given skills name that are not registered abilities.
     *
     * @param array<string, string> $skills slug => raw document
     * @return string[]
     */
    public static function tool_problems(array $skills): array
    {
        $manifest = (require Edition_Tree::root() . '/tests/support/ability-manifest.php')['abilities'];
        $bad      = [];
        foreach ($skills as $slug => $raw) {
            preg_match_all('~wpmcp/[a-z0-9-]+~', $raw, $m);
            foreach (array_unique($m[0]) as $tool) {
                if (! isset($manifest[ $tool ])) {
                    $bad[] = $slug . ' names ' . $tool;
                }
            }
        }
        return $bad;
    }

    public function test_every_tool_a_bundled_skill_names_is_registered(): void
    {
        $this->assertNotSame([], self::bundled(), 'The scan found no bundled skills.');
        $this->assertSame([], self::tool_problems(self::bundled()), 'A bundled skill names a tool that does not exist.');
    }

    public function test_every_operation_a_bundled_skill_names_exists(): void
    {
        $slugs = array_fill_keys(array_merge(array_keys(self::bundled()), self::PRO_OWNED_SKILLS), true);
        $bad   = self::operation_problems($this, self::bundled(), self::operations(true), $slugs);
        $this->assertSame([], array_values(array_intersect(self::PRO_OWNED_SKILLS, array_keys(self::bundled()))), 'PRO_OWNED_SKILLS lists a public skill; drop it from the list.');

        $this->assertSame([], $bad, 'A bundled skill names an operation that does not exist.');
    }

    public function test_integration_skills_stay_short(): void
    {
        foreach (self::bundled() as $slug => $raw) {
            if (0 !== strpos($slug, 'integrations') && ! in_array($slug, [ 'wpmcp-block-editing', 'wpmcp-security-incident', 'wpmcp-performance-caching' ], true)) {
                continue;
            }
            $this->assertLessThanOrEqual(self::MAX_SKILL_BYTES, strlen($raw), $slug . ' is longer than an integration playbook should be.');
        }
    }

    public function test_the_integrations_router_names_every_area_skill(): void
    {
        $router = self::bundled()['integrations'] ?? '';
        $this->assertNotSame('', $router, 'The integrations router skill is missing.');

        foreach (array_keys(self::bundled()) as $slug) {
            if (0 === strpos($slug, 'integrations/')) {
                $this->assertStringContainsString('`' . $slug . '`', $router, 'The router does not point at ' . $slug);
            }
        }
    }
}
