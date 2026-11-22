<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Hooks;

use WPMCP\Api\V1\Attribute\On_Rollback;
use WPMCP\Modules\Site_Gate_Settings\Adapter\Maintenance_Themes;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Puts the Maintenance plugin's designer themes back as objects after a
 * rollback (issue #1099). Once a rollback has put mtnc-options back (a
 * toggle's or a settings write's undo), each theme is decoded again the way
 * the plugin decodes it.
 */
final class Maintenance_Rollback
{
    public function __construct(private readonly Maintenance_Themes $themes)
    {
    }

    /**
     * @param mixed $names the options a rollback just restored (a list of
     *                     names; any value, as the action passes it)
     */
    #[On_Rollback]
    public function after_options_restored(mixed $names = []): void
    {
        if (in_array($this->themes->option(), (array) $names, true)) {
            $this->themes->rehydrate();
        }
    }
}
