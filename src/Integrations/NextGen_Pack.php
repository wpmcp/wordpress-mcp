<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * NextGEN Gallery (issue #705) on the plugin-data pair, free tier, so it
 * adds no top-level tools. Ops are prefixed nextgen- because the pair is
 * shared: list-galleries and get-gallery read; reorder-images and
 * update-image write, off until a site opts in with
 * wpmcp_enable_nextgen_write, each undoable (NextGen_Operations).
 *
 * Presence is filterable (wpmcp_nextgen_active, default: NGG_PLUGIN_VERSION
 * is defined).
 */
final class NextGen_Pack
{
    public const PLUGIN_FILES = [ 'nextgen-gallery/nggallery.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_nextgen_active', defined('NGG_PLUGIN_VERSION'));
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
            'message' => sprintf('NextGEN Gallery is %s on this site. Install and activate NextGEN Gallery (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'nextgen-gallery', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new NextGen_Operations(new NextGen_Store(), static fn (): bool|array => self::presence()))->definitions();
    }

    /** After an undo put rows of NextGEN's images table back: flush its caches (Gallery_Packs::attach_refreshers()). */
    public static function after_rows_restored(string $table): void
    {
        $store = new NextGen_Store();
        if (strtolower($table) === strtolower($store->table(NextGen_Store::PICTURES))) {
            $store->flush();
        }
    }
}
