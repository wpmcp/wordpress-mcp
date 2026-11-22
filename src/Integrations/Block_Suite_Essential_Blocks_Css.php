<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Drops the CSS Essential Blocks generated for a post (issue #1009), the way
 * its StyleHandler::remove_frontend_assets() does on save_post.
 *
 * Essential Blocks writes uploads/eb-style/eb-style-{id}.min.css (for a
 * template or template part, full-site-editor/full-site-editor-{id}.min.css)
 * and frontend/frontend-{id}.min.css from the blockMeta of the post's
 * blocks, on the first view that finds them missing, and marks a
 * NotificationX bar that has none with an eb_nx_nocss_{id} transient. A
 * rollback restores the post without save_post, so after writes and
 * rollbacks the files and the marker go here, and Essential Blocks rebuilds
 * them from the blocks on the next view.
 */
final class Block_Suite_Essential_Blocks_Css
{
    private const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];

    /** @param string $style_dir uploads/eb-style/, with its trailing slash */
    public function __construct(private readonly string $style_dir)
    {
    }

    public function drop(int $post_id): void
    {
        if ($post_id < 1) {
            return;
        }
        if (false !== get_transient('eb_nx_nocss_' . $post_id)) {
            delete_transient('eb_nx_nocss_' . $post_id);
        }
        $files = [ $this->style_dir . 'frontend/frontend-' . $post_id . '.min.css' ];
        $files[] = in_array(get_post_type($post_id), self::TEMPLATE_TYPES, true)
            ? $this->style_dir . 'full-site-editor/full-site-editor-' . $post_id . '.min.css'
            : $this->style_dir . 'eb-style-' . $post_id . '.min.css';
        foreach ($files as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
    }
}
