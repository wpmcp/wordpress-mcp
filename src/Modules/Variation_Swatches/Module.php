<?php

declare(strict_types=1);

namespace WPMCP\Modules\Variation_Swatches;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Variation Swatches for WooCommerce (issue #1096): module declaration only. Its ops still live in
 * src/Integrations and reach the plugin-data pair through
 * Woo_Conversion_Packs until that pair reads the registry; the module
 * declares their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'variation-swatches',
    group: 'variation-swatches',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['woo-variation-swatches/woo-variation-swatches.php'], name: 'Variation Swatches for WooCommerce', slug: 'woo-variation-swatches'),
    test_plugins: ['woo-variation-swatches/woo-variation-swatches.php'],
)]
final class Module
{
}
