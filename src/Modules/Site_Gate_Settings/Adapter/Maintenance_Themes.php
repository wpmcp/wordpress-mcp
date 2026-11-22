<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Maintenance plugin's designer themes, as stored in mtnc-options (issue
 * #1099).
 */
interface Maintenance_Themes
{
    /** The option the themes live in. */
    public function option(): string;

    /** Decodes every theme that came back as an array into the stdClass tree the plugin reads. */
    public function rehydrate(): void;
}
