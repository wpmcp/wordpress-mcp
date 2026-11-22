<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cart Abandonment Recovery for WooCommerce (issue #1096, wordpress.org slug
 * woo-cart-abandonment-recovery) registration glue for the plugin-data pair,
 * free tier: presence and the ops, built from Cart_Abandonment_Store,
 * Cart_Abandonment_Snapshots and Cart_Abandonment_Operations.
 *
 * Presence is filterable (wpmcp_cart_abandonment_active, default:
 * CARTFLOWS_CA_VER is defined). Without the plugin the ops answer
 * plugin_missing or plugin_inactive; before its tables exist they answer
 * cart_abandonment_tables_missing.
 */
final class Cart_Abandonment_Pack
{
    public const PLUGIN_FILE = 'woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_cart_abandonment_active', defined('CARTFLOWS_CA_VER'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence(Cart_Abandonment_Store $store): bool|array
    {
        $url = admin_url('plugins.php');
        if (! self::active()) {
            $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf('Cart Abandonment Recovery for WooCommerce is %s on this site. Install and activate it with WooCommerce (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
                'data'    => [ 'plugin' => 'woo-cart-abandonment-recovery', 'plugins_url' => $url ],
            ];
        }
        if (! $store->has_tables()) {
            return [
                'code'    => 'cart_abandonment_tables_missing',
                'message' => sprintf('Cart Abandonment Recovery has not created its tables yet. Deactivate and reactivate it (%s), then try again.', $url),
                'data'    => [ 'plugins_url' => $url ],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (! $db instanceof \wpdb) {
            return [];
        }
        $store = new Cart_Abandonment_Store($db);

        return ( new Cart_Abandonment_Operations(
            $store,
            new Cart_Abandonment_Snapshots($store),
            static fn () => self::presence($store)
        ) )->definitions();
    }
}
