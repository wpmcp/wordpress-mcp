<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage of a Flatsome (UX Builder) layout: the shortcodes in a page's
 * post_content, and the same for a UX Block, a post of the `blocks` type the
 * theme registers. UX Builder keeps no layout meta of its own, so the post
 * snapshot taken by Safe_Mutation::run() covers a write completely and a
 * rollback restores it byte for byte.
 *
 * Flatsome is a theme: it is present when it, or the parent of the active
 * child theme, is the template (get_template() is 'flatsome' either way).
 * The wpmcp_flatsome_active filter overrides that check for tests.
 */
final class Flatsome_Content
{
    public const THEME = 'flatsome';

    /** The post type Flatsome stores UX Blocks in. */
    public const BLOCK_POST_TYPE = 'blocks';

    /** Whether Flatsome is the active theme or the parent of it. */
    public static function theme_active(): bool
    {
        $active = function_exists('get_template') && self::THEME === strtolower((string) get_template());

        return (bool) apply_filters('wpmcp_flatsome_active', $active);
    }

    /** The active Flatsome's version, or null when it is not active. */
    public static function theme_version(): ?string
    {
        if (! self::theme_active() || ! function_exists('wp_get_theme')) {
            return null;
        }
        $version = (string) wp_get_theme(self::THEME)->get('Version');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs Flatsome active. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('themes.php');

        return new \WP_Error(
            'plugin_missing',
            "Flatsome is not the active theme (or the parent of it) on this site, so UX Builder pages and blocks cannot be changed. Install and activate Flatsome ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Flatsome', 'url' => $url]
        );
    }

    public static function get_content(int $post_id): string
    {
        $post = get_post($post_id);

        return $post ? (string) $post->post_content : '';
    }

    /** sha256 of the stored layout, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        return hash('sha256', self::get_content($post_id));
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        $content = self::get_content($post_id);
        $tree    = Flatsome_Shortcodes::tree($content);

        return [
            'post_id'        => $post_id,
            'builder'        => 'flatsome',
            'post_type'      => (string) get_post_type($post_id),
            'plugin_active'  => self::theme_active(),
            'plugin_version' => self::theme_version(),
            'tree'           => $tree,
            'content'        => $content,
            'hash'           => hash('sha256', $content),
            'blocks'         => self::placed_blocks($tree),
        ];
    }

    /** Write the layout to post_content, as UX Builder's save does. */
    public static function save(int $post_id, string $content): void
    {
        Save_Filters::update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);
    }

    /**
     * The ids (or slugs) of the UX Blocks a layout places with [block id],
     * in document order.
     *
     * @param array<int,array<string,mixed>> $tree
     * @return string[]
     */
    private static function placed_blocks(array $tree): array
    {
        $ids = [];
        foreach ($tree as $node) {
            if ('block' === $node['tag'] && isset($node['attrs']['id'])) {
                $ids[] = (string) $node['attrs']['id'];
            }
            if (isset($node['children'])) {
                $ids = array_merge($ids, self::placed_blocks($node['children']));
            }
        }

        return $ids;
    }
}
