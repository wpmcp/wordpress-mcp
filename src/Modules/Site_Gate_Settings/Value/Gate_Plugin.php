<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The five gate plugins whose settings these ops edit (issue #1099), by the
 * slug the site gate toggles use, in the order 'auto' looks for them.
 */
enum Gate_Plugin: string
{
    case Seedprod = 'coming-soon';
    case Under_Construction = 'under-construction-page';
    case Wp_Maintenance_Mode = 'wp-maintenance-mode';
    case Maintenance = 'maintenance';
    case Cmp = 'cmp-coming-soon-maintenance';

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(static fn (self $plugin): string => $plugin->value, self::cases());
    }
}
