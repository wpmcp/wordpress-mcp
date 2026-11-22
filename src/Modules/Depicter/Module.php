<?php

declare(strict_types=1);

namespace WPMCP\Modules\Depicter;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Depicter (issue #1091): module declaration only. Its ops live in
 * src/Integrations (Depicter_Pack) on the plugin-data pair until that pair
 * reads the registry; the module declares their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'depicter',
    group: 'depicter',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['depicter/depicter.php'], name: 'Depicter', slug: 'depicter'),
    test_plugins: ['depicter/depicter.php'],
)]
final class Module
{
}
