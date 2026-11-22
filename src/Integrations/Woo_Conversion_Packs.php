<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WooCommerce conversion add-ons of issue #1096 as one entry for the
 * plugin-data pair: Variation Swatches for WooCommerce, Product Feed Pro and
 * Cart Abandonment Recovery. It only merges their packs, so the pair (which
 * does not read the module registry yet) takes one registration line for
 * the three.
 */
final class Woo_Conversion_Packs
{
    public static function active(): bool
    {
        return Variation_Swatches_Pack::active() || Product_Feed_Pack::active() || Cart_Abandonment_Pack::active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(Variation_Swatches_Pack::operations(), Product_Feed_Pack::operations(), Cart_Abandonment_Pack::operations());
    }

    /**
     * operation_hosts() groups: plugin name, served by several plugins, ops.
     *
     * @return list<array{0: string, 1: bool, 2: array<string, array<string, mixed>>}>
     */
    public static function host_groups(): array
    {
        return [
            [ 'Variation Swatches for WooCommerce', false, Variation_Swatches_Pack::operations() ],
            [ 'Product Feed Pro for WooCommerce', false, Product_Feed_Pack::operations() ],
            [ 'Cart Abandonment Recovery for WooCommerce', false, Cart_Abandonment_Pack::operations() ],
        ];
    }
}
