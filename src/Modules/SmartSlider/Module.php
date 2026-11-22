<?php

declare(strict_types=1);

namespace WPMCP\Modules\SmartSlider;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Smart Slider 3 (issue #705): module declaration only. Its ops still live in
 * src/Integrations (SmartSlider_Pack) and reach the plugin-data pair through
 * Gallery_Packs until that pair reads the registry; the module declares
 * their opt-in gate (Opt_In).
 */
#[Module_Declaration(
    id: 'smartslider',
    group: 'smartslider',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['smart-slider-3/smart-slider-3.php', 'nextend-smart-slider3-pro/nextend-smart-slider3-pro.php'], name: 'Smart Slider 3', slug: 'smart-slider-3'),
    test_plugins: ['smart-slider-3/smart-slider-3.php'],
)]
final class Module
{
}
