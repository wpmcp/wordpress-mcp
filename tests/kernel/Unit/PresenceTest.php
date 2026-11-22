<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Kernel\Presence;

final class PresenceTest extends TestCase
{
    public function test_reads_active_plugins_once(): void
    {
        $reads = 0;
        $presence = new Presence(static function () use (&$reads): array {
            ++$reads;
            return ['redirection/redirection.php'];
        });

        self::assertTrue($presence->plugin('redirection/redirection.php'));
        self::assertFalse($presence->plugin('woocommerce/woocommerce.php'));
        self::assertSame(1, $reads);
    }

    public function test_any_one_plugin_of_a_list_satisfies_it(): void
    {
        $presence = Presence::with_plugins(['seo-by-rank-math/rank-math.php']);

        self::assertTrue($presence->satisfies(new Requires(plugin: ['wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php'])));
        self::assertFalse($presence->satisfies(new Requires(plugin: ['wordpress-seo/wp-seo.php'])));
    }

    public function test_an_empty_requirement_is_always_met(): void
    {
        self::assertTrue(Presence::with_plugins([])->satisfies(new Requires()));
        self::assertTrue(Presence::with_plugins([])->satisfies(null));
    }

    public function test_class_function_and_constant_checks(): void
    {
        $presence = Presence::with_plugins([]);

        self::assertTrue($presence->satisfies(new Requires(class: \ArrayObject::class)));
        self::assertFalse($presence->satisfies(new Requires(class: 'No_Such_Class_' . __LINE__)));
        self::assertTrue($presence->satisfies(new Requires(function: 'array_map')));
        self::assertFalse($presence->satisfies(new Requires(function: 'no_such_function_' . __LINE__)));
        self::assertTrue($presence->satisfies(new Requires(constant: 'PHP_VERSION')));
        self::assertFalse($presence->satisfies(new Requires(constant: 'NO_SUCH_CONSTANT_' . __LINE__)));
    }

    public function test_every_named_kind_must_hold(): void
    {
        $presence = Presence::with_plugins(['a/a.php']);

        self::assertFalse($presence->satisfies(new Requires(plugin: ['a/a.php'], class: 'No_Such_Class_' . __LINE__)));
    }

    public function test_theme_and_multisite_use_the_injected_sources(): void
    {
        $presence = new Presence(static fn (): array => [], static fn (): array => ['astra', 'astra-child'], static fn (): bool => false);

        self::assertTrue($presence->satisfies(new Requires(theme: 'astra')));
        self::assertFalse($presence->satisfies(new Requires(theme: 'kadence')));
        self::assertFalse($presence->satisfies(new Requires(multisite: true)));
    }

    public function test_accepts_the_compiled_array_form(): void
    {
        self::assertTrue(Presence::with_plugins(['a/a.php'])->satisfies(['plugin' => ['a/a.php']]));
    }
}
