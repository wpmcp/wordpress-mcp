<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MetaSlider (issue #705) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed metaslider- because the pair is shared:
 * list-sliders and get-slider read; create-slider, add-slides,
 * reorder-slides and update-slide write, off until a site opts in with
 * wpmcp_enable_metaslider_write, each undoable (MetaSlider_Operations).
 *
 * Presence is filterable (wpmcp_metaslider_active, default: METASLIDER_VERSION
 * is defined).
 */
final class MetaSlider_Pack
{
    public const PLUGIN_FILES = [ 'ml-slider/ml-slider.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_metaslider_active', defined('METASLIDER_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence(): bool|array
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf('MetaSlider is %s on this site. Install and activate MetaSlider (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'ml-slider', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new MetaSlider_Operations(new MetaSlider_Store(), static fn () => self::presence()))->definitions();
    }
}
