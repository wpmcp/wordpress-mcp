<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Api\V1\Value\Context;
use WPMCP\Api\V1\Value\Op;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;
use WPMCP\Api\V1\Version;

final class ValueTest extends TestCase
{
    public function test_requires_round_trips_through_its_scalar_export(): void
    {
        $requires = new Requires(
            plugin: ['redirection/redirection.php'],
            class: 'Red_Item',
            name: 'Redirection',
            slug: 'redirection',
        );

        $exported = $requires->to_array();

        self::assertSame(['redirection/redirection.php'], $exported['plugin']);
        self::assertSame('Red_Item', $exported['class']);
        self::assertArrayNotHasKey('function', $exported, 'unset fields are left out so locks stay small');
        self::assertEquals($requires, Requires::from_array($exported));
    }

    public function test_requires_with_changes_returns_a_new_instance(): void
    {
        $base = new Requires(plugin: ['a/a.php']);
        $changed = $base->with_name('A');

        self::assertNotSame($base, $changed);
        self::assertNull($base->name);
        self::assertSame('A', $changed->name);
        self::assertSame(['a/a.php'], $changed->plugin);
    }

    public function test_tier_normalizes_enum_cases_and_addon_strings(): void
    {
        self::assertSame('free', Tier::normalize(Tier::Free));
        self::assertSame('pro', Tier::normalize('pro'));
        self::assertSame('addon:acme-forms', Tier::normalize('addon:acme-forms'));
    }

    /** @return iterable<string, array{string}> */
    public static function bad_tiers(): iterable
    {
        yield 'unknown' => ['gold'];
        yield 'empty addon slug' => ['addon:'];
        yield 'bad addon slug' => ['addon:Acme Forms'];
    }

    /** @dataProvider bad_tiers */
    public function test_tier_rejects_unknown_values(string $tier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tier::normalize($tier);
    }

    public function test_op_derives_the_same_hints_as_today(): void
    {
        // Mirrors WPMCP\MCP\Ability's constructor defaults.
        self::assertSame([true, false, true], Op::Read->hints());
        self::assertSame([false, false, false], Op::Create->hints());
        self::assertSame([false, false, true], Op::Update->hints());
        self::assertSame([false, true, false], Op::Delete->hints());
    }

    public function test_context_values_are_stable_strings(): void
    {
        self::assertSame(
            ['cli', 'cron', 'ajax', 'admin', 'rest', 'mcp', 'frontend'],
            array_map(static fn (Context $c): string => $c->value, Context::cases())
        );
    }

    public function test_version_constants_are_exposed(): void
    {
        self::assertMatchesRegularExpression('/^1\.\d+\.\d+$/', Version::VERSION);
        self::assertSame(1, Version::REGISTRY_FORMAT);
    }
}
