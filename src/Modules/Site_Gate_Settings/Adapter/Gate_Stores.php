<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The module boundary to the gate plugins (issue #1099): which store a call
 * means, and whether that plugin is closing the site.
 */
interface Gate_Stores
{
    /** The store of the plugin $named, or with 'auto' the one gate plugin that is active. */
    public function resolve(string $named): Gate_Store|Refusal;

    /** Whether the plugin is closing the site right now, by its own settings. */
    public function gate_active(Gate_Store $store): bool;
}
