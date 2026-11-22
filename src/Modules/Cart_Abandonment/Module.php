<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cart_Abandonment;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cart Abandonment Recovery for WooCommerce (issue #1096): module declaration only. Its ops still live in
 * src/Integrations and reach the plugin-data pair through
 * Woo_Conversion_Packs until that pair reads the registry; the module
 * declares their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'cart-abandonment',
    group: 'cart-abandonment',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php'], name: 'Cart Abandonment Recovery for WooCommerce', slug: 'woo-cart-abandonment-recovery'),
    test_plugins: ['woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php'],
)]
final class Module
{
}
