<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Api\V1\Attribute\Provides;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots travel as JSON and come back as arrays, while Maintenance keeps
 * each theme in mtnc-options['options']['themes'] as the stdClass tree
 * json_decode() gives without assoc, and reads it with ->. rehydrate()
 * decodes each theme again the way the plugin decodes it (issue #1099).
 */
#[Provides(Maintenance_Themes::class)]
final class Wp_Maintenance_Themes implements Maintenance_Themes
{
    private const OPTION = 'mtnc-options';

    public function option(): string
    {
        return self::OPTION;
    }

    public function rehydrate(): void
    {
        $stored = get_option(self::OPTION);
        if (! is_array($stored) || ! is_array($stored['options'] ?? null) || ! is_array($stored['options']['themes'] ?? null)) {
            return;
        }
        $themes = $stored['options']['themes'];
        $changed = false;
        foreach ($themes as $id => $theme) {
            if (is_array($theme)) {
                $themes[ $id ] = json_decode((string) wp_json_encode($theme));
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }
        $stored['options']['themes'] = $themes;
        update_option(self::OPTION, $stored);
    }
}
