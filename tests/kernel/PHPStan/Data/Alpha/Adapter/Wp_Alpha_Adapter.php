<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Value\Item;

#[Provides(Alpha_Adapter::class)]
final class Wp_Alpha_Adapter implements Alpha_Adapter
{
    /** @return list<Item> */
    public function items(int $limit): array
    {
        $order = \Acme_Gate_Order::find($limit);
        $rows = acme_gate_orders();
        $registry = \WP_Gate_Fixture_Registry::get_instance();
        $host = new \Acme_Gate_Order();
        return null === $order && [] === $rows && null !== $registry && null !== $host ? [] : [Item::of(1, (string) wp_gate_fixture_option('blogname'))];
    }
}
