<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Forms_Integration;
use WPMCP\Tests\Support\Edition_Tree;

require_once __DIR__ . '/../../support/forms-adapters.php';

/**
 * The Pro half of the forms adapter list check (issue #66). The adapter list
 * the registration and smoke tests rely on (tests/support/forms-adapters.php)
 * must name exactly the concrete Forms_Integration subclasses shipped in
 * src/Integrations; the public half
 * (tests/free/Integrations/FormsAdapterConformanceTest) covers the adapters
 * at public paths, this one the adapter pack at Pro paths: each must be in
 * the list and in FormsPackConformanceTest, at the pro tier.
 */
class FormsAdapterConformanceProTest extends \WP_UnitTestCase
{
    public function test_the_forms_adapter_pack_list_is_complete_and_tiered(): void
    {
        $found = [];
        foreach (array_keys(Edition_Tree::pro_sources()) as $path) {
            if (1 !== preg_match('#^src/Integrations/([A-Za-z0-9_]+_Integration)\.php$#', $path, $m)) {
                continue;
            }
            $class = 'WPMCP\\Integrations\\' . $m[1];
            $ref   = new \ReflectionClass($class);
            if (! $ref->isAbstract() && $ref->isSubclassOf(Forms_Integration::class)) {
                $found[ ( new $class() )->integration() ] = $class;
            }
        }
        $listed = array_filter(
            wpmcp_forms_adapter_classes(),
            static fn (string $class): bool => Edition_Tree::is_pro_path('src/Integrations/' . substr(strrchr($class, '\\'), 1) . '.php')
        );
        ksort($found);
        ksort($listed);
        $this->assertNotSame([], $found, 'Expected the forms adapter pack in the Pro sources');
        $this->assertSame($listed, $found, 'tests/support/forms-adapters.php must list every forms adapter of the pack');

        $suite = array_map(static fn (array $row): string => $row[0], FormsPackConformanceTest::adapters());
        ksort($suite);
        $this->assertSame($found, $suite, 'FormsPackConformanceTest must cover every forms adapter of the pack');

        foreach ($found as $slug => $class) {
            $this->assertSame('pro', ( new $class() )->tier(), "{$slug} belongs to the adapter pack");
        }
    }
}
