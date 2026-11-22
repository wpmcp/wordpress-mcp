<?php

declare(strict_types=1);

namespace WPMCP\Modules\Modula;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Modula (issue #705): module declaration only. Its ops still live in
 * src/Integrations (Modula_Pack) and reach the plugin-data pair through
 * Gallery_Packs until that pair reads the registry; the module declares
 * their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'modula',
    group: 'modula',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['modula-best-grid-gallery/Modula.php'], name: 'Modula', slug: 'modula-best-grid-gallery'),
    test_plugins: ['modula-best-grid-gallery/Modula.php'],
)]
final class Module
{
}
