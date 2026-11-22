<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SearchWP (issue #993) registration glue for the theme pair, through
 * Ops_Status_Packs: presence and the ops, built from SearchWP_Store and
 * SearchWP_Operations.
 *
 * Presence is the SEARCHWP_VERSION constant, filterable through
 * wpmcp_searchwp_active. The rebuild runs SearchWP's own index reset
 * (\SearchWP::$index->reset(), the "Rebuild Index" button) and is
 * filterable through wpmcp_searchwp_rebuild_callback.
 */
final class SearchWP_Status
{
    public const PLUGIN_FILE = 'searchwp/index.php';

    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_searchwp_active', defined('SEARCHWP_VERSION'));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        global $wpdb;

        return ( new SearchWP_Operations(
            new SearchWP_Store($wpdb),
            static fn () => Ops_Status_Packs::presence(self::is_active(), 'searchwp_inactive', 'SearchWP'),
            static fn () => apply_filters('wpmcp_searchwp_rebuild_callback', self::default_rebuild())
        ) )->definitions();
    }

    /** SearchWP's own index reset, or null when it is not loaded. */
    private static function default_rebuild(): ?\Closure
    {
        if (! class_exists('\SearchWP') || ! isset(\SearchWP::$index) || ! is_object(\SearchWP::$index) || ! method_exists(\SearchWP::$index, 'reset')) {
            return null;
        }
        return static function (): void {
            \SearchWP::$index->reset();
        };
    }
}
