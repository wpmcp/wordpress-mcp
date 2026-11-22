<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Product Feed Pro for WooCommerce (issue #1096, AdTribes, wordpress.org
 * slug woo-product-feed-pro) registration glue for the plugin-data pair,
 * free tier: presence and the ops, built from Product_Feed_Store and
 * Product_Feed_Operations.
 *
 * Presence is filterable (wpmcp_product_feed_active, default:
 * WOOCOMMERCESEA_PLUGIN_VERSION is defined). Without it the ops answer
 * plugin_missing or plugin_inactive; the refresh also needs Action
 * Scheduler, which WooCommerce ships.
 */
final class Product_Feed_Pack
{
    public const PLUGIN_FILE = 'woo-product-feed-pro/woocommerce-sea.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_product_feed_active', defined('WOOCOMMERCESEA_PLUGIN_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence(): bool|array
    {
        $url = admin_url('plugins.php');
        if (! self::active()) {
            $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf('Product Feed Pro for WooCommerce is %s on this site. Install and activate it with WooCommerce (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
                'data'    => [ 'plugin' => 'woo-product-feed-pro', 'plugins_url' => $url ],
            ];
        }
        if (! function_exists('as_schedule_single_action')) {
            return [
                'code'    => 'plugin_missing',
                'message' => sprintf('Product Feed Pro needs WooCommerce, which is not active on this site. Activate WooCommerce (%s), then try again.', $url),
                'data'    => [ 'plugin' => 'woocommerce', 'plugins_url' => $url ],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return ( new Product_Feed_Operations(new Product_Feed_Store(), static fn () => self::presence()) )->definitions();
    }
}
