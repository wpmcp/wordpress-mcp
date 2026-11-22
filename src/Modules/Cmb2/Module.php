<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;
use WPMCP\Api\V1\Value\Tier;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CMB2 (issue #1089): the boxes registered in code and their field values
 * for a post or an options page, read and written on the theme pair.
 * Module declaration only (no executable logic).
 *
 * The Requires names the plugin for refusals; the ops themselves stay
 * listed without it, since CMB2 is often bundled in a theme. No Owns_Data:
 * the post meta and options page option arrays it writes belong to the
 * site's CMB2 boxes, and uninstalling WP MCP must never delete them.
 */
#[Module_Declaration(
    id: 'cmb2',
    order: 9000,
    tier: Tier::Free,
    requires: new Requires(plugin: ['cmb2/init.php'], name: 'CMB2', slug: 'cmb2'),
)]
final class Module
{
}
