<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Envira Gallery (issue #705; Lite, and Pro, which shares its storage) on
 * the plugin-data pair, free tier, so it adds no top-level tools. Ops are
 * prefixed envira- because the pair is shared: list-galleries and
 * get-gallery read; create-gallery, add-images, remove-images and
 * reorder-images write, off until a site opts in with
 * wpmcp_enable_envira_write, each undoable (Envira_Operations).
 *
 * Presence is filterable (wpmcp_envira_active, default: Envira is running,
 * Envira_Gallery_Common for Lite or Envira_Gallery for Pro; Lite defines
 * its constants before it may bail out for Pro, so they prove nothing).
 */
final class Envira_Pack
{
    public const PLUGIN_FILES = [ 'envira-gallery-lite/envira-gallery-lite.php', 'envira-gallery/envira-gallery.php' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_envira_active', class_exists('Envira_Gallery_Common') || class_exists('Envira_Gallery'));
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
            'message' => sprintf('Envira Gallery is %s on this site. Install and activate Envira Gallery (%s), then try again.', $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => 'envira-gallery-lite', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new Envira_Operations(new Envira_Store(), static fn () => self::presence()))->definitions();
    }

    /** After a gallery was trashed (the undo of a create among them): Envira's inactive flag and caches (Plugin::boot()). */
    public static function after_trash(int $post_id): void
    {
        if (Envira_Store::GALLERY === get_post_type($post_id)) {
            (new Envira_Store())->set_trashed($post_id, true);
        }
    }

    /** After a gallery left the trash: clear Envira's inactive flag (Plugin::boot()). */
    public static function after_untrash(int $post_id): void
    {
        if (Envira_Store::GALLERY === get_post_type($post_id)) {
            (new Envira_Store())->set_trashed($post_id, false);
        }
    }

    /** After an undo restored a gallery post: drop Envira's cached copies of it (Plugin::boot()). */
    public static function after_post_restored(int $post_id): void
    {
        if (Envira_Store::GALLERY === get_post_type($post_id)) {
            (new Envira_Store())->flush($post_id);
        }
    }
}
