<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Modula (issue #705) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed modula- because the pair is shared:
 * list-galleries and get-gallery read; create-gallery, add-images,
 * remove-images and reorder-images write, off until a site opts in with
 * wpmcp_enable_modula_write, each undoable (Modula_Operations).
 *
 * Presence is filterable (wpmcp_modula_active, default: MODULA_LITE_VERSION
 * is defined).
 */
final class Modula_Pack
{
    public const PLUGIN_FILES = [ 'modula-best-grid-gallery/Modula.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_modula_active', defined('MODULA_LITE_VERSION'));
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
            'message' => sprintf('Modula is %s on this site. Install and activate Modula (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'modula-best-grid-gallery', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new Modula_Operations(new Modula_Store(), static fn () => self::presence()))->definitions();
    }
}
