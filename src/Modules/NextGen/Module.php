<?php

declare(strict_types=1);

namespace WPMCP\Modules\NextGen;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * NextGEN Gallery (issue #705): module declaration only. Its ops still live in
 * src/Integrations (NextGen_Pack) and reach the plugin-data pair through
 * Gallery_Packs until that pair reads the registry; the module declares
 * their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'nextgen',
    group: 'nextgen',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['nextgen-gallery/nggallery.php'], name: 'NextGEN Gallery', slug: 'nextgen-gallery'),
    test_plugins: ['nextgen-gallery/nggallery.php'],
)]
final class Module
{
}
