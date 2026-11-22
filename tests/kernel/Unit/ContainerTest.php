<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Container_Error;
use WPMCP\Tests\Kernel\Fixture\Clock;
use WPMCP\Tests\Kernel\Fixture\Fixed_Clock;
use WPMCP\Tests\Kernel\Fixture\Needs_Clock;
use WPMCP\Tests\Kernel\Fixture\No_Deps;

final class ContainerTest extends TestCase
{
    public function test_resolves_compiled_dependencies_and_interface_bindings(): void
    {
        $container = new Container(
            factories: [Needs_Clock::class => [Clock::class, No_Deps::class], No_Deps::class => []],
            bindings: [Clock::class => Fixed_Clock::class],
        );

        $service = $container->get(Needs_Clock::class);

        self::assertInstanceOf(Fixed_Clock::class, $service->clock);
        self::assertSame(43, $service->tick());
    }

    public function test_memoizes_instances_per_request(): void
    {
        $container = new Container(factories: [No_Deps::class => []]);

        self::assertSame($container->get(No_Deps::class), $container->get(No_Deps::class));
    }

    public function test_falls_back_to_a_no_argument_constructor_when_not_strict(): void
    {
        $container = new Container();

        self::assertInstanceOf(No_Deps::class, $container->get(No_Deps::class));
    }

    public function test_strict_mode_refuses_a_class_missing_from_the_map(): void
    {
        $container = new Container(strict: true);

        $this->expectException(Container_Error::class);
        $this->expectExceptionMessage(No_Deps::class);
        $container->get(No_Deps::class);
    }

    public function test_an_unbound_interface_is_an_error(): void
    {
        $container = new Container(factories: [Needs_Clock::class => [Clock::class, No_Deps::class]]);

        $this->expectException(Container_Error::class);
        $container->get(Needs_Clock::class);
    }

    public function test_preset_instances_win_over_factories(): void
    {
        $clock = new Fixed_Clock();
        $container = new Container(factories: [Needs_Clock::class => [Clock::class, No_Deps::class]]);
        $container->set(Clock::class, $clock);

        self::assertSame($clock, $container->get(Needs_Clock::class)->clock);
        self::assertTrue($container->has(Clock::class));
    }

    public function test_a_dependency_cycle_is_reported_not_recursed_forever(): void
    {
        $container = new Container(factories: [No_Deps::class => [Needs_Clock::class], Needs_Clock::class => [No_Deps::class]]);

        $this->expectException(Container_Error::class);
        $this->expectExceptionMessage('cycle');
        $container->get(No_Deps::class);
    }
}
