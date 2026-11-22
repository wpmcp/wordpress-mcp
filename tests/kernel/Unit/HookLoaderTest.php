<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Hook_Loader;
use WPMCP\Kernel\Request_Context;
use WPMCP\Tests\Kernel\Fixture\Needs_Clock;
use WPMCP\Tests\Kernel\Fixture\No_Deps;
use WPMCP\Tests\Kernel\Fixture\Static_Hooks;

final class HookLoaderTest extends TestCase
{
    /** @var list<array{hook: string, callback: array{0: string, 1: string}|\Closure, priority: int, args: int, filter: bool}> */
    private array $added = [];

    private function loader(string $uri = '/hello/', ?Container $container = null): Hook_Loader
    {
        $this->added = [];
        return new Hook_Loader(
            $container ?? new Container(),
            new Request_Context(uri: $uri),
            function (string $hook, array|\Closure $callback, int $priority, int $args, bool $filter): void {
                $this->added[] = compact('hook', 'callback', 'priority', 'args', 'filter');
            }
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function hook(array $overrides = []): array
    {
        return $overrides + [
            'module' => 'demo',
            'kind' => 'hook',
            'hook' => 'init',
            'class' => Static_Hooks::class,
            'method' => 'on_init',
            'static' => true,
            'priority' => 10,
            'args' => 1,
            'filter' => false,
            'contexts' => [],
        ];
    }

    public function test_static_methods_attach_as_plain_callables_without_autoloading(): void
    {
        $count = $this->loader()->attach([self::hook(['class' => 'WPMCP\\Not\\Loaded\\Yet'])], static fn (): bool => true);

        self::assertSame(1, $count);
        self::assertSame(['WPMCP\\Not\\Loaded\\Yet', 'on_init'], $this->added[0]['callback']);
        self::assertFalse(class_exists('WPMCP\\Not\\Loaded\\Yet', false));
    }

    public function test_instance_methods_resolve_through_the_container_only_when_fired(): void
    {
        $before = No_Deps::$constructed;
        $this->loader()->attach([self::hook(['class' => No_Deps::class, 'method' => 'handle', 'static' => false])], static fn (): bool => true);

        self::assertSame($before, No_Deps::$constructed);
        $result = ($this->added[0]['callback'])(['x' => 1]);
        self::assertSame(['echo' => ['x' => 1]], $result);
        self::assertSame($before + 1, No_Deps::$constructed);
    }

    public function test_passes_priority_args_and_filter_through(): void
    {
        $this->loader()->attach([self::hook(['priority' => 1, 'args' => 3, 'filter' => true, 'hook' => 'the_content'])], static fn (): bool => true);

        self::assertSame('the_content', $this->added[0]['hook']);
        self::assertSame(1, $this->added[0]['priority']);
        self::assertSame(3, $this->added[0]['args']);
        self::assertTrue($this->added[0]['filter']);
    }

    public function test_skips_hooks_for_another_context(): void
    {
        $count = $this->loader('/hello/')->attach([
            self::hook(['contexts' => ['admin']]),
            self::hook(['contexts' => ['frontend']]),
        ], static fn (): bool => true);

        self::assertSame(1, $count);
    }

    public function test_skips_hooks_of_inactive_modules(): void
    {
        $count = $this->loader()->attach([
            self::hook(['module' => 'off']),
            self::hook(['module' => 'on']),
        ], static fn (string $module): bool => 'on' === $module);

        self::assertSame(1, $count);
    }

    public function test_an_instance_hook_with_dependencies_gets_them_injected(): void
    {
        $container = new Container(factories: [Needs_Clock::class => [\WPMCP\Tests\Kernel\Fixture\Clock::class, No_Deps::class]], bindings: [\WPMCP\Tests\Kernel\Fixture\Clock::class => \WPMCP\Tests\Kernel\Fixture\Fixed_Clock::class]);
        $this->loader('/', $container)->attach([self::hook(['class' => Needs_Clock::class, 'method' => 'tick', 'static' => false])], static fn (): bool => true);

        self::assertSame(45, ($this->added[0]['callback'])(3));
    }

    public function test_default_adder_uses_add_action_and_add_filter_when_present(): void
    {
        // Without WordPress the default adder must not fatal; it reports zero.
        $loader = new Hook_Loader(new Container(), new Request_Context(uri: '/'));

        self::assertSame(function_exists('add_action') ? 1 : 0, $loader->attach([self::hook()], static fn (): bool => true));
    }
}
