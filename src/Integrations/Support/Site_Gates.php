<?php

declare(strict_types=1);

namespace WPMCP\Integrations\Support;

use WPMCP\Maintenance\Site_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The site gate detection a module may depend on (design section 1.5):
 * whether a gate plugin is active and closing the site, and SeedProd's
 * settings, read exactly as the site gate toggles read them. An injectable
 * service over Site_Gate until that class moves into this namespace.
 */
final class Site_Gates
{
    public function plugin_active(string $slug): bool
    {
        return Site_Gate::plugin_active($slug);
    }

    /** Whether the plugin is closing the site right now, by its own settings. */
    public function gate_active(string $slug): bool
    {
        $gate = Site_Gate::plugin_gate($slug);
        return null !== $gate && true === ($gate['active'] ?? false);
    }

    /** @return array<string, mixed> */
    public function seedprod_settings(): array
    {
        return Site_Gate::seedprod_settings();
    }
}
