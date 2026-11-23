<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Kses_Safety;
use WPMCP\Safety\Post_Write_Refused;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Bricks builder structure, stored in the
 * `_bricks_page_content_2` postmeta key. Bricks itself stores the element
 * tree as a NATIVE (serialized) array there (see Bricks includes/ajax.php,
 * which calls update_post_meta with the raw $content array), so this reader
 * returns arrays and the writer stores arrays, keeping what an agent writes
 * fully readable by Bricks. A legacy JSON string is still tolerated on read.
 *
 * Read and written directly as postmeta rather than through the Bricks
 * plugin's runtime, matching how Elementor_Page_Data treats `_elementor_data`:
 * because it is ordinary postmeta on the page post, the existing post snapshot
 * in Safe_Mutation::run() already captures and restores it, so no safety-core
 * change is needed for these edits to be undoable.
 */
class Bricks_Content
{
    public const META_KEY = '_bricks_page_content_2';

    public const KSES_SUBJECT = 'Bricks page';

    /**
     * The row Bricks reads a post's elements from: the header or footer row
     * for a header or footer template, else the content row (issue #914).
     */
    public static function row_key(int $post_id): string
    {
        return Bricks_Cache::area_key(Bricks_Cache::area($post_id));
    }

    /** Return the element tree as an array, or null if missing/invalid. */
    public static function get(int $post_id): ?array
    {
        $raw = get_post_meta($post_id, self::row_key($post_id), true);

        // How real Bricks stores it: a native array in postmeta.
        if (is_array($raw)) {
            return $raw;
        }

        // Tolerate a legacy JSON-encoded string (older data / external writer).
        if (is_string($raw) && '' !== $raw) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * The refusal for a layout holding a new value wp_kses_post would change,
     * or new code (see Bricks_Code_Fields), or null. Bricks' own save filters
     * the values of a user without unfiltered_html, and post meta skips
     * kses, so the check runs here.
     * Values the page already holds, or $known holds (the stored source a
     * copy or a restore takes them from), were stored by someone allowed to.
     *
     * @param array<mixed> $elements
     * @param mixed        $known    Decoded data whose strings count as already stored.
     */
    public static function kses_refusal(int $post_id, array $elements, mixed $known = null): ?\WP_Error
    {
        return (new Kses_Safety())->values_refusal([self::get($post_id), $known], $elements, self::KSES_SUBJECT, new Bricks_Code_Fields());
    }

    /**
     * @param array<mixed> $elements
     * @param mixed        $known    See kses_refusal().
     * @throws Post_Write_Refused When a new value would not survive kses; nothing is written.
     */
    public static function save(int $post_id, array $elements, mixed $known = null): void
    {
        $refusal = self::kses_refusal($post_id, $elements, $known);
        if (null !== $refusal) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Write_Refused escapes the message it is given.
            throw new Post_Write_Refused($refusal);
        }

        // Store a native array exactly as Bricks does so Bricks can read it.
        // wp_slash keeps clean (unslashed) data byte-accurate through
        // update_post_meta's internal wp_unslash.
        update_post_meta($post_id, self::row_key($post_id), wp_slash($elements));

        // What Bricks' own save does after the layout row (issue #914): the
        // editor mode that makes Bricks render the page, then the CSS file.
        Bricks_Cache::sync_editor_mode($post_id);
        Bricks_Cache::regenerate($post_id);
    }
}
