<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Smart Slider 3 (issue #705) on the plugin-data pair, free tier, so it
 * adds no top-level tools. Ops are prefixed smartslider- because the pair
 * is shared: list-sliders and get-slider read; reorder-slides and
 * update-slide write, off until a site opts in with
 * wpmcp_enable_smartslider_write, each undoable (SmartSlider_Operations).
 *
 * Presence is filterable (wpmcp_smartslider_active, default:
 * NEXTEND_SMARTSLIDER_3, which Smart Slider defines once it has loaded).
 */
final class SmartSlider_Pack
{
    public const PLUGIN_FILES = [ 'smart-slider-3/smart-slider-3.php', 'nextend-smart-slider3-pro/nextend-smart-slider3-pro.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_smartslider_active', defined('NEXTEND_SMARTSLIDER_3'));
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
            'message' => sprintf('Smart Slider 3 is %s on this site. Install and activate Smart Slider 3 (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'smart-slider-3', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new SmartSlider_Operations(new SmartSlider_Store(), static fn () => self::presence()))->definitions();
    }

    /** After an undo put rows of Smart Slider's slides table back: clear its caches (Plugin::boot()). */
    public static function after_rows_restored(string $table): void
    {
        $store = new SmartSlider_Store();
        if (strtolower($table) === strtolower($store->table(SmartSlider_Store::SLIDES))) {
            $store->refresh_all();
        }
    }
}
