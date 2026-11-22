<?php

declare(strict_types=1);

namespace WPMCP\Modules\Hustle;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hustle (issue #1091): module declaration only. Its ops live in
 * src/Integrations (Hustle_Pack) on the plugin-data pair until that pair
 * reads the registry; the module declares their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'hustle',
    group: 'hustle',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['wordpress-popup/popover.php'], name: 'Hustle', slug: 'wordpress-popup'),
    test_plugins: ['wordpress-popup/popover.php'],
)]
final class Module
{
}
