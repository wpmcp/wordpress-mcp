<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site gate settings (issue #1099): the gate page content and bypass rules
 * of SeedProd, Under Construction, WP Maintenance Mode, Maintenance and CMP,
 * on the theme pair next to the site gate toggles. Module declaration only
 * (no executable logic).
 *
 * No Requires: the ops stay listed while none of the five plugins is active
 * and answer site_gate_plugin_inactive, as the toggles do. No Owns_Data: the
 * options it writes belong to those plugins, and uninstalling WPMCP must
 * never delete them.
 */
#[Module_Declaration(
    id: 'site-gate-settings',
    order: 9000,
    tier: Tier::Free,
)]
final class Module
{
}
