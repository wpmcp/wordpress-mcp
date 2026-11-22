<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Ability_Loader;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Lazy_Handler;
use WPMCP\MCP\Ability;
use WPMCP\Tests\Kernel\Fixture\No_Deps;

final class AbilityLoaderTest extends TestCase
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function descriptor(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'wpmcp/demo-read',
            'module' => 'demo',
            'tier' => 'free',
            'description' => 'Read a demo thing',
            'input' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']]],
            'capability' => 'edit_posts',
            'domain' => 'content',
            'operation' => 'read',
            'read_only' => null,
            'destructive' => null,
            'idempotent' => null,
            'read_keys' => [],
            'class' => No_Deps::class,
            'method' => 'handle',
            'objects' => null,
            'op_capability' => null,
            'condition' => null,
        ];
    }

    public function test_builds_abilities_with_lazy_handlers_and_todays_defaults(): void
    {
        $before = No_Deps::$constructed;
        $abilities = (new Ability_Loader(new Container()))->load([self::descriptor()]);

        self::assertCount(1, $abilities);
        $a = $abilities[0];
        self::assertInstanceOf(Ability::class, $a);
        self::assertSame('wpmcp/demo-read', $a->name);
        self::assertSame('free', $a->tier);
        self::assertSame(['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']]], $a->input_schema);
        self::assertTrue($a->read_only_hint);
        self::assertFalse($a->destructive_hint);
        self::assertTrue($a->idempotent_hint);
        self::assertInstanceOf(Lazy_Handler::class, $a->handler);
        self::assertSame([No_Deps::class, 'handle'], $a->handler->target());
        self::assertSame($before, No_Deps::$constructed, 'loading constructs no handler');
        self::assertNull($a->objects);
        self::assertNull($a->op_capability);
    }

    public function test_explicit_hints_override_the_operation_defaults(): void
    {
        $a = (new Ability_Loader(new Container()))->load([self::descriptor(['operation' => 'update', 'destructive' => true])])[0];

        self::assertFalse($a->read_only_hint);
        self::assertTrue($a->destructive_hint);
        self::assertTrue($a->idempotent_hint);
        self::assertSame('update', $a->operation);
    }

    public function test_objects_and_op_capability_become_lazy_closures(): void
    {
        $a = (new Ability_Loader(new Container()))->load([self::descriptor(['objects' => 'objects', 'op_capability' => 'op_capability'])])[0];

        self::assertInstanceOf(\Closure::class, $a->objects);
        self::assertSame([['type' => 'post', 'id' => 7]], ($a->objects)(['post_id' => 7]));
        self::assertSame('manage_options', ($a->op_capability)(['operation' => 'x']));
    }

    public function test_keeps_compiled_order_and_honours_conditions_and_filters(): void
    {
        $loader = new Ability_Loader(new Container());
        $abilities = $loader->load(
            [
                self::descriptor(['name' => 'wpmcp/b']),
                self::descriptor(['name' => 'wpmcp/skip', 'condition' => [self::class, 'condition_false']]),
                self::descriptor(['name' => 'wpmcp/a']),
                self::descriptor(['name' => 'wpmcp/filtered']),
            ],
            static fn (array $d): bool => 'wpmcp/filtered' !== $d['name']
        );

        self::assertSame(['wpmcp/b', 'wpmcp/a'], array_map(static fn (Ability $a): string => $a->name, $abilities));
    }

    public function test_the_tier_resolver_can_override_a_tier(): void
    {
        $loader = new Ability_Loader(new Container(), static fn (string $name, string $tier): string => 'wpmcp/demo-read' === $name ? 'free' : $tier);

        self::assertSame('free', $loader->load([self::descriptor(['tier' => 'pro'])])[0]->tier);
    }

    public static function condition_false(): bool
    {
        return false;
    }
}
