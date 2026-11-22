<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Variation Swatches for WooCommerce (issue #1096, Emran Ahmed's plugin,
 * wordpress.org slug woo-variation-swatches) registration glue for the
 * plugin-data pair, free tier: presence, the ops (built from
 * Variation_Swatches_Store, Variation_Swatches_Snapshots and
 * Variation_Swatches_Operations) and the cache refresh after an undo.
 *
 * Two other plugins share the name; this pack follows the one whose
 * constant it checks. Presence is filterable (wpmcp_variation_swatches_active,
 * default: WOO_VARIATION_SWATCHES_PLUGIN_VERSION is defined and WooCommerce's
 * attribute functions are loaded). Without it the ops answer plugin_missing
 * or plugin_inactive.
 */
final class Variation_Swatches_Pack
{
    public const PLUGIN_FILE = 'woo-variation-swatches/woo-variation-swatches.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_variation_swatches_active', defined('WOO_VARIATION_SWATCHES_PLUGIN_VERSION') && function_exists('wc_get_attribute_taxonomies'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence(): bool|array
    {
        if (self::active()) {
            return true;
        }
        $url       = admin_url('plugins.php');
        $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf('Variation Swatches for WooCommerce is %s on this site. Install and activate it with WooCommerce (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'woo-variation-swatches', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $db = Typed::db();
        if (null === $db) {
            return [];
        }
        $store = new Variation_Swatches_Store($db);

        return ( new Variation_Swatches_Operations(
            $store,
            new Variation_Swatches_Snapshots($store),
            static fn () => self::presence()
        ) )->definitions();
    }

    /**
     * After an undo restored attribute rows (wpmcp_rollback_db_rows_restored),
     * let WooCommerce and the swatches plugin drop their cached copies, as
     * their own save does.
     */
    public static function after_rows_restored(string $table): void
    {
        $db = Typed::db();
        if (null === $db) {
            return;
        }
        $store = new Variation_Swatches_Store($db);
        if (strtolower($table) === strtolower($store->table()) && function_exists('wc_get_attribute_taxonomies')) {
            $store->refresh_after_restore();
        }
    }
}
