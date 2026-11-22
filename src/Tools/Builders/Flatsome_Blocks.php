<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Flatsome UX Blocks on the builder pair. A UX Block is a post of the
 * `blocks` type holding UX Builder shortcodes, and a page places one with
 * the theme's `[block id="<id or slug>"]` shortcode, which renders the
 * block's current content (a reference, not a copy).
 *
 * - list: the templates scope of get-builder-content, kept to the blocks
 *   the caller may read.
 * - get / edit: a block is a post, so get-builder-content and the element
 *   operations of update-builder-content take its id as post_id.
 * - insert: add with element {block: <id>} places `[block id="<id>"]`.
 * - create: save_template saves a page element (or the whole page) as a new
 *   block, recorded for undo (rollback-operation trashes it). A blank block
 *   is a plain post of the `blocks` type, which the post tools create.
 */
final class Flatsome_Blocks
{
    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        $posts     = Readable_Posts::get_posts([
            'post_type'      => Flatsome_Content::BLOCK_POST_TYPE,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $templates[] = [
                'id'        => (int) $post->ID,
                'title'     => (string) $post->post_title,
                'slug'      => (string) $post->post_name,
                'status'    => (string) $post->post_status,
                'elements'  => count(Flatsome_Shortcodes::tree((string) $post->post_content)),
                'shortcode' => self::shortcode((int) $post->ID),
            ];
        }

        return [
            'builder'       => 'flatsome',
            'scope'         => 'templates',
            'plugin_active' => Flatsome_Content::theme_active(),
            'templates'     => $templates,
        ];
    }

    /** The shortcode that places a block in a layout. */
    public static function shortcode(int $block_id): string
    {
        return '[block id="' . $block_id . '"]';
    }

    /**
     * Place a block under a container ('' is the top level) at a child
     * index.
     *
     * @param mixed $block_id
     * @return array{0:string,1:string} new content, and the inserted path
     * @throws \InvalidArgumentException
     */
    public static function insert(string $content, string $to, ?int $index, $block_id): array
    {
        $id   = is_int($block_id) || (is_string($block_id) && ctype_digit($block_id)) ? (int) $block_id : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || Flatsome_Content::BLOCK_POST_TYPE !== $post->post_type) {
            throw new \InvalidArgumentException(esc_html('element {block} needs the id of a UX Block (scope templates lists them).'));
        }
        if (! current_user_can('read_post', $id)) {
            throw new \InvalidArgumentException(esc_html("You are not allowed to read UX Block {$id}."));
        }

        return Flatsome_Shortcodes::insert($content, $to, $index, self::shortcode($id));
    }

    /**
     * Save an element of a page (or, with no path, the whole page) as a new
     * UX Block. The page is left as it is.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_template(int $post_id, array $args)
    {
        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_flatsome_operation', 'save_template needs element {title}: the name of the new UX Block.');
        }

        $path    = (string) ($args['path'] ?? '');
        $content = Flatsome_Content::get_content($post_id);
        if ('' === $path) {
            $markup = $content;
        } else {
            try {
                $markup = Flatsome_Shortcodes::markup($content, $path);
            } catch (\InvalidArgumentException $e) {
                return new \WP_Error('invalid_flatsome_operation', $e->getMessage());
            }
        }

        $type_object = get_post_type_object(Flatsome_Content::BLOCK_POST_TYPE);
        if (! $type_object) {
            return Flatsome_Content::unavailable();
        }
        if (! current_user_can($type_object->cap->edit_posts)) {
            return new \WP_Error('forbidden', 'Saving a UX Block needs the capability to create blocks posts.');
        }

        $id = wp_insert_post(wp_slash([
            'post_title'   => sanitize_text_field($title),
            'post_content' => $markup,
            'post_status'  => current_user_can($type_object->cap->publish_posts) ? 'publish' : 'pending',
            'post_type'    => Flatsome_Content::BLOCK_POST_TYPE,
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'flatsome',
            'template_id'  => $id,
            'shortcode'    => self::shortcode($id),
        ];
    }
}
