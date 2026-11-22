<?php

declare(strict_types=1);

namespace WPMCP\Modules\MetaSlider;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MetaSlider (issue #705): module declaration only. Its ops still live in
 * src/Integrations (MetaSlider_Pack) and reach the plugin-data pair through
 * Gallery_Packs until that pair reads the registry; the module declares
 * their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'metaslider',
    group: 'metaslider',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['ml-slider/ml-slider.php'], name: 'MetaSlider', slug: 'ml-slider'),
    test_plugins: ['ml-slider/ml-slider.php'],
)]
final class Module
{
}
