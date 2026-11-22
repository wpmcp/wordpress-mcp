<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Operation_Registry;
use WPMCP\Kernel\Presence;
use WPMCP\Kernel\Registry;
use WPMCP\Tests\Kernel\Fixture\Links_Pack;
use WPMCP\Tests\Kernel\Fixture\Pro_Pack;
use WPMCP\Tests\Kernel\Fixture\Reviews_Pack;

final class OperationRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Links_Pack::$calls = 0;
        Links_Pack::$active = true;
    }

    /** @return list<array<string, mixed>> */
    private static function contributors(): array
    {
        return [
            [
                'dispatcher' => 'plugin-data', 'module' => 'links', 'class' => Links_Pack::class, 'method' => 'operations',
                'host' => 'Pretty Links', 'hosts' => null, 'several' => false, 'summary' => 'Pretty Links links', 'summary_order' => 20,
                'order' => 10, 'tier' => 'free', 'availability' => 'active', 'requires' => null, 'variant' => null,
                'ops' => ['links-list' => ['mode' => 'read'], 'links-delete' => ['mode' => 'destructive']],
            ],
            [
                'dispatcher' => 'plugin-data', 'module' => 'reviews', 'class' => Reviews_Pack::class, 'method' => 'operations',
                'host' => 'Site Reviews', 'hosts' => null, 'several' => false, 'summary' => 'Site Reviews reviews', 'summary_order' => 10,
                'order' => 20, 'tier' => 'free', 'availability' => null, 'requires' => ['plugin' => ['site-reviews/site-reviews.php']], 'variant' => null,
                'ops' => ['reviews-list' => ['mode' => 'read'], 'links-list' => ['mode' => 'read']],
            ],
            [
                'dispatcher' => 'plugin-data', 'module' => 'pro', 'class' => Pro_Pack::class, 'method' => 'operations',
                'host' => 'Pro Plugin', 'hosts' => null, 'several' => false, 'summary' => null, 'summary_order' => 30,
                'order' => 30, 'tier' => 'pro', 'availability' => null, 'requires' => null, 'variant' => null,
                'ops' => ['pro-op' => ['mode' => 'write', 'tier' => 'pro']],
            ],
        ];
    }

    private function registry(bool $pro = true, array $plugins = ['site-reviews/site-reviews.php'], ?\Closure $variant = null): Operation_Registry
    {
        return new Operation_Registry(
            Registry::from_arrays(['modules' => []], [], ['plugin-data' => self::contributors()]),
            Presence::with_plugins($plugins),
            new Container(),
            static fn (string $tier): bool => 'pro' !== $tier || $pro,
            $variant,
        );
    }

    public function test_compiled_metadata_answers_without_running_contributors(): void
    {
        $ops = $this->registry();

        self::assertTrue($ops->any_available('plugin-data'));
        self::assertTrue($ops->has_destructive('plugin-data'));
        self::assertSame(['links-list', 'links-delete', 'reviews-list', 'pro-op'], $ops->operation_names('plugin-data'));
        self::assertSame(0, Links_Pack::$calls, 'no contributor runs for catalog metadata');
    }

    public function test_summary_joins_fragments_in_summary_order_and_skips_empty_ones(): void
    {
        self::assertSame('Site Reviews reviews, Pretty Links links', $this->registry()->summary('plugin-data'));
    }

    public function test_hosts_map_each_op_to_its_host_and_honour_the_tier(): void
    {
        self::assertSame(
            [
                'links-list' => ['name' => 'Site Reviews', 'several' => false],
                'links-delete' => ['name' => 'Pretty Links', 'several' => false],
                'reviews-list' => ['name' => 'Site Reviews', 'several' => false],
            ],
            $this->registry(false)->hosts('plugin-data')
        );
    }

    public function test_operations_merge_in_order_with_the_later_contributor_winning_as_today(): void
    {
        $ops = $this->registry()->operations('plugin-data');

        self::assertSame(['links-list', 'links-delete', 'reviews-list', 'pro-op'], array_keys($ops));
        self::assertSame('reviews', ($ops['links-list']['handler'])());
        self::assertSame(1, Links_Pack::$calls);
    }

    public function test_operations_are_memoized_per_request(): void
    {
        $ops = $this->registry();
        $ops->operations('plugin-data');
        $ops->operations('plugin-data');

        self::assertSame(1, Links_Pack::$calls);
    }

    public function test_tier_filtering_drops_pro_contributors(): void
    {
        self::assertArrayNotHasKey('pro-op', $this->registry(false)->operations('plugin-data'));
        self::assertNotContains('pro-op', $this->registry(false)->operation_names('plugin-data'));
    }

    public function test_availability_uses_the_grandfathered_predicate_or_presence(): void
    {
        Links_Pack::$active = false;
        self::assertFalse($this->registry(false, [])->any_available('plugin-data'), 'a pro contributor with no requirement counts only when permitted');
        self::assertTrue($this->registry(true, [])->any_available('plugin-data'));
        self::assertTrue($this->registry(true, ['site-reviews/site-reviews.php'])->any_available('plugin-data'));
    }

    public function test_an_op_level_tier_is_honoured_inside_a_free_contributor(): void
    {
        $registry = new Operation_Registry(
            Registry::from_arrays(['modules' => []], [], ['plugin-data' => [
                ['dispatcher' => 'plugin-data', 'module' => 'links', 'class' => Links_Pack::class, 'method' => 'operations', 'host' => 'Pretty Links', 'hosts' => null, 'several' => false, 'summary' => 'x', 'summary_order' => 1, 'order' => 1, 'tier' => 'free', 'availability' => null, 'requires' => null, 'variant' => null, 'ops' => ['links-list' => ['mode' => 'read'], 'links-delete' => ['mode' => 'destructive', 'tier' => 'pro']]],
            ]]),
            Presence::with_plugins([]),
            new Container(),
            static fn (string $tier): bool => 'pro' !== $tier,
        );

        self::assertSame(['links-list'], $registry->operation_names('plugin-data'));
        self::assertSame(['links-list'], array_keys($registry->hosts('plugin-data')));
        self::assertFalse($registry->has_destructive('plugin-data'));
    }

    public function test_a_contributor_with_several_hosts_uses_its_compiled_host_map(): void
    {
        $registry = new Operation_Registry(
            Registry::from_arrays(['modules' => []], [], ['plugin-data' => [
                ['dispatcher' => 'plugin-data', 'module' => 'lms', 'class' => Links_Pack::class, 'method' => 'operations', 'host' => null, 'hosts' => ['links-list' => 'Tutor LMS', 'links-delete' => 'LifterLMS'], 'several' => false, 'summary' => 'x', 'summary_order' => 1, 'order' => 1, 'tier' => 'free', 'availability' => null, 'requires' => null, 'variant' => null, 'ops' => ['links-list' => ['mode' => 'read'], 'links-delete' => ['mode' => 'destructive']]],
            ]]),
            Presence::with_plugins([]),
            new Container(),
        );

        self::assertSame(
            ['links-list' => ['name' => 'Tutor LMS', 'several' => false], 'links-delete' => ['name' => 'LifterLMS', 'several' => false]],
            $registry->hosts('plugin-data')
        );
    }

    public function test_method_name_callables_resolve_to_injected_instance_methods(): void
    {
        $registry = new Operation_Registry(
            Registry::from_arrays(['modules' => []], [], ['plugin-data' => [
                ['dispatcher' => 'plugin-data', 'module' => 'inj', 'class' => \WPMCP\Tests\Kernel\Fixture\Injected_Ops::class, 'method' => 'operations', 'static' => true, 'host' => 'X', 'hosts' => null, 'several' => false, 'summary' => 'x', 'summary_order' => 1, 'order' => 1, 'tier' => 'free', 'availability' => null, 'requires' => null, 'variant' => null, 'ops' => ['injected-read' => ['mode' => 'read']]],
            ]]),
            Presence::with_plugins([]),
            new Container(factories: [\WPMCP\Tests\Kernel\Fixture\Injected_Ops::class => [\WPMCP\Tests\Kernel\Fixture\No_Deps::class], \WPMCP\Tests\Kernel\Fixture\No_Deps::class => []]),
        );
        $op = $registry->operations('plugin-data')['injected-read'];

        self::assertInstanceOf(\Closure::class, $op['handler']);
        self::assertSame(['echo' => ['a' => 1]], ($op['handler'])(['a' => 1]));
        self::assertNull(($op['validate'])([]));
        self::assertSame('kept as is', $op['description']);
    }

    public function test_an_unknown_dispatcher_is_empty(): void
    {
        $ops = $this->registry();

        self::assertSame([], $ops->operations('nope'));
        self::assertFalse($ops->any_available('nope'));
        self::assertSame('', $ops->summary('nope'));
    }

    public function test_a_variant_contributor_counts_only_when_the_variant_matches(): void
    {
        $registry = new Operation_Registry(
            Registry::from_arrays(['modules' => []], [], ['theme' => [
                ['dispatcher' => 'theme', 'module' => 'kadence', 'class' => Links_Pack::class, 'method' => 'operations', 'host' => 'Kadence', 'hosts' => null, 'several' => false, 'summary' => 'Kadence', 'summary_order' => 1, 'order' => 1, 'tier' => 'free', 'availability' => null, 'requires' => null, 'variant' => 'kadence', 'ops' => ['links-list' => ['mode' => 'read']]],
            ]]),
            Presence::with_plugins([]),
            new Container(),
            static fn (): bool => true,
            static fn (string $dispatcher): ?string => 'astra',
        );

        self::assertSame([], $registry->operations('theme'));
        self::assertFalse($registry->any_available('theme'));
    }
}
