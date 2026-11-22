<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kadence Blocks advanced forms (issue #676) registration glue for the
 * plugin-data pair, free tier: presence and the ops, built from
 * Kadence_Forms_Store and Kadence_Forms_Operations.
 *
 * Presence is filterable (wpmcp_kadence_forms_active, default:
 * KADENCE_BLOCKS_VERSION is defined).
 */
final class Kadence_Forms_Pack
{
    public const PLUGIN_FILE = 'kadence-blocks/kadence-blocks.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_kadence_forms_active', defined('KADENCE_BLOCKS_VERSION'));
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
            'message' => sprintf('Kadence Blocks is %s on this site. Install and activate Kadence Blocks (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'kadence-blocks', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return ( new Kadence_Forms_Operations(new Kadence_Forms_Store(), static fn () => self::presence()) )->definitions();
    }
}
