<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Jetpack Forms (issue #676) registration glue for the plugin-data pair,
 * free tier: presence and the ops, built from Jetpack_Forms_Store and
 * Jetpack_Forms_Operations.
 *
 * Presence is filterable (wpmcp_jetpack_forms_active, default: the forms
 * package's Feedback class is loaded).
 */
final class Jetpack_Forms_Pack
{
    public const PLUGIN_FILE = 'jetpack/jetpack.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_jetpack_forms_active', class_exists('Automattic\\Jetpack\\Forms\\ContactForm\\Feedback'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
        $url       = admin_url('plugins.php');
        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf('Jetpack Forms is %s on this site. Install and activate Jetpack with its Forms feature (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'jetpack', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        global $wpdb;
        return ( new Jetpack_Forms_Operations(new Jetpack_Forms_Store($wpdb), static fn () => self::presence()) )->definitions();
    }
}
