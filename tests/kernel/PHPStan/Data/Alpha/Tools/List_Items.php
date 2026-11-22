<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Tools;

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Contract\Ability_Handler;
use WPMCP\Api\V1\Value\Op;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter\Alpha_Adapter;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Value\Item;

// Everything here is allowed: no rule reports this file.
#[Ability(name: 'wpmcp/alpha-list', description: 'List alpha items', operation: Op::Read)]
final class List_Items implements Ability_Handler
{
    public function __construct(private readonly Alpha_Adapter $adapter)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        $op = Op::from('read');
        $item = Item::of(1, 'First');
        $second = new Item(2, 'Second');
        $requires = new Requires(plugin: ['acme/acme.php']);
        $error = new \WP_Gate_Fixture_Error('alpha', 'nope');
        $when = new \DateTimeImmutable('now');
        $object = new class () {
        };
        $length = strlen((string) wp_gate_fixture_option('blogname'));
        $registry = \WP_Gate_Fixture_Registry::get_instance();
        $stamp = \DateTimeImmutable::createFromFormat('Y', '2026');
        if ($length < 0) {
            throw new Alpha_Failure('negative');
        }
        $same = self::class;
        return [$this->adapter->items(10), $op, $second, $item->with_title('x'), $requires, $error, $when, $object, $registry, $stamp, $same];
    }
}
