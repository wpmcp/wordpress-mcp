<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ACF Extended (issue #1089): its options pages, dynamic forms, block types
 * and field group settings, read on the theme pair. Reads only. Module
 * declaration only (no executable logic).
 *
 * The Requires names the plugin for refusals; the ops stay listed without
 * it and answer acfe_inactive. No Owns_Data: nothing is stored.
 */
#[Module_Declaration(
    id: 'acf-extended',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['acf-extended/acf-extended.php'], name: 'ACF Extended', slug: 'acf-extended'),
)]
final class Module
{
}
