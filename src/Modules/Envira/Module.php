<?php

declare(strict_types=1);

namespace WPMCP\Modules\Envira;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Envira Gallery (issue #705): module declaration only. Its ops still live in
 * src/Integrations (Envira_Pack) and reach the plugin-data pair through
 * Gallery_Packs until that pair reads the registry; the module declares
 * their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'envira',
    group: 'envira',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['envira-gallery-lite/envira-gallery-lite.php', 'envira-gallery/envira-gallery.php'], name: 'Envira Gallery', slug: 'envira-gallery-lite'),
    test_plugins: ['envira-gallery-lite/envira-gallery-lite.php'],
)]
final class Module
{
}
