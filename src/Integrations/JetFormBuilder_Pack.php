<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * JetFormBuilder (issue #676) registration glue for the plugin-data pair,
 * free tier: presence and the ops, built from JetFormBuilder_Store,
 * JetFormBuilder_Entry_Snapshots and JetFormBuilder_Operations.
 *
 * Presence is filterable (wpmcp_jetformbuilder_active, default:
 * JET_FORM_BUILDER_VERSION is defined). Without the plugin the ops answer
 * plugin_missing or plugin_inactive; with it but before its first saved
 * record (its tables are created then) they answer
 * jetformbuilder_tables_missing.
 */
final class JetFormBuilder_Pack
{
    public const PLUGIN_FILE = 'jetformbuilder/jet-form-builder.php';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_jetformbuilder_active', defined('JET_FORM_BUILDER_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public static function presence(JetFormBuilder_Store $store)
    {
        $url = admin_url('plugins.php');
        if (! self::active()) {
            $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf('JetFormBuilder is %s on this site. Install and activate JetFormBuilder (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
                'data'    => [ 'plugin' => 'jetformbuilder', 'plugins_url' => $url ],
            ];
        }
        if (! $store->has_tables()) {
            return [
                'code'    => 'jetformbuilder_tables_missing',
                'message' => sprintf('JetFormBuilder has not created its record table %s yet; it does so when a form with the Save Form Record action is first submitted.', $store->table(JetFormBuilder_Store::RECORDS)),
                'data'    => [ 'plugins_url' => $url ],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        global $wpdb;
        $store = new JetFormBuilder_Store($wpdb);

        return ( new JetFormBuilder_Operations(
            $store,
            new JetFormBuilder_Entry_Snapshots($store),
            static fn () => self::presence($store)
        ) )->definitions();
    }
}
