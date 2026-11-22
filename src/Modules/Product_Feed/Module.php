<?php

declare(strict_types=1);

namespace WPMCP\Modules\Product_Feed;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Product Feed Pro for WooCommerce (issue #1096): module declaration only. Its ops still live in
 * src/Integrations and reach the plugin-data pair through
 * Woo_Conversion_Packs until that pair reads the registry; the module
 * declares their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'product-feed',
    group: 'product-feed',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['woo-product-feed-pro/woocommerce-sea.php'], name: 'Product Feed Pro for WooCommerce', slug: 'woo-product-feed-pro'),
    test_plugins: ['woo-product-feed-pro/woocommerce-sea.php'],
)]
final class Module
{
}
