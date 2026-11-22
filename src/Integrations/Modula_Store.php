<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Modula's storage (issue #705), as Modula 3.0 keeps it. New rows come from
 * Modula's own Modula_Gallery_Upload::build_attachment_image_row() while
 * Modula is loaded.
 *
 * A gallery is a non-public `modula-gallery` post with core's post
 * capabilities (includes/admin/cpt/class-modula-cpt.php). Its images are
 * the modula-images meta: a list of rows in display order, each naming its
 * attachment by id with its layout keys (halign, valign, width, height,
 * link, target); a row's empty title or alt reads the attachment's. Its
 * settings are the modula-settings meta (Modula_CPT_Fields_Helper::get_defaults()).
 * A gallery made in the newer editor also keeps a JSON copy of its images in
 * modula_images_v2, which Modula rebuilds from modula-images through
 * \Modula\V2\Meta_Sync::sync_modula_images_v2_from_list(), keeping the rows
 * only that copy has (includes/v2/class-meta-sync.php).
 *
 * Only storage lives here; Modula_Operations validates and dispatches.
 */
final class Modula_Store
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const GALLERY = 'modula-gallery';

    public const IMAGES = 'modula-images';

    public const SETTINGS = 'modula-settings';

    public const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    public function is_gallery(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::GALLERY === $post->post_type && 'trash' !== $post->post_status;
    }

    /** @return array{galleries: list<\WP_Post>, total: int} */
    public function galleries(?string $status, string $search, int $page, int $per_page): array
    {
        $query = [
            'post_type'      => self::GALLERY,
            'post_status'    => null === $status ? self::STATUSES : [ $status ],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if ('' !== $search) {
            $query['s'] = $search;
        }
        $q     = Readable_Posts::query($query);
        $posts = array_values(array_filter($q->posts ?? [], static fn (mixed $post): bool => $post instanceof \WP_Post));
        return [ 'galleries' => $posts, 'total' => $q->found_posts ];
    }

    /**
     * The image rows that name an attachment, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(int $id): array
    {
        $rows = get_post_meta($id, self::IMAGES, true);
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::filled($row['id'] ?? null)) {
                $out[] = Typed::map($row);
            }
        }
        return $out;
    }

    /** @return list<int> */
    public function image_ids(int $id): array
    {
        return array_map(static fn (array $row): int => self::row_id($row), $this->rows($id));
    }

    /** @param array<string, mixed> $row */
    public static function row_id(array $row): int
    {
        return Typed::int($row['id'] ?? null);
    }

    /** @return array<string, mixed> */
    public function settings(int $id): array
    {
        $settings = get_post_meta($id, self::SETTINGS, true);
        return is_array($settings) ? Typed::map($settings) : [];
    }

    /**
     * The row an image joins a gallery with: Modula's own builder while
     * Modula is loaded, otherwise the row it builds for a grid gallery.
     *
     * @return array<string, mixed>
     */
    public function new_row(int $gallery, int $image): array
    {
        // Modula loads its builder on demand, as its own REST routes and abilities do.
        $path = defined('MODULA_PATH') ? Typed::str(constant('MODULA_PATH')) . 'includes/admin/helpers/class-modula-gallery-upload.php' : '';
        if (! class_exists('Modula_Gallery_Upload') && '' !== $path && is_readable($path)) {
            require_once $path;
        }
        $upload = is_callable([ 'Modula_Gallery_Upload', 'get_instance' ]) ? call_user_func([ 'Modula_Gallery_Upload', 'get_instance' ]) : null;
        if (is_object($upload) && method_exists($upload, 'build_attachment_image_row')) {
            $row = $upload->build_attachment_image_row($gallery, $image);
            if ($row instanceof \WP_Error) {
                throw new Operation_Refused('image_not_found', $row->get_error_message(), [ 'image_id' => $image ]);
            }
            return Typed::map($row);
        }
        $attachment = get_post($image);
        return [
            'id'          => $image,
            'alt'         => sanitize_text_field(Typed::str(get_post_meta($image, '_wp_attachment_image_alt', true))),
            'title'       => sanitize_text_field($attachment instanceof \WP_Post ? $attachment->post_title : ''),
            'description' => wp_filter_post_kses($attachment instanceof \WP_Post ? $attachment->post_excerpt : ''),
            'halign'      => 'center',
            'valign'      => 'middle',
            'link'        => '',
            'target'      => 0,
            'width'       => 2,
            'height'      => 2,
        ];
    }

    /**
     * Store the rows, then let Modula rebuild its newer copy from them.
     *
     * @param array<array<string, mixed>> $rows
     */
    public function save_rows(int $id, array $rows): void
    {
        update_post_meta($id, self::IMAGES, wp_slash(array_values($rows)));
        $sync = [ '\\Modula\\V2\\Meta_Sync', 'sync_modula_images_v2_from_list' ];
        if (is_callable($sync)) {
            call_user_func($sync, $id, array_values($rows));
        }
        clean_post_cache($id);
    }

    /** Insert a gallery post with Modula's default settings. */
    public function insert_gallery(string $title, string $status): int
    {
        $id = wp_insert_post([
            'post_type'   => self::GALLERY,
            'post_title'  => wp_slash($title),
            'post_status' => $status,
            'post_author' => get_current_user_id(),
        ], true);
        if ($id instanceof \WP_Error) {
            throw new Operation_Refused('insert_failed', $id->get_error_message());
        }
        $ensure   = [ '\\Modula\\V2\\Meta_Sync', 'ensure_default_settings' ];
        $defaults = [ 'Modula_CPT_Fields_Helper', 'get_defaults' ];
        if (is_callable($ensure)) {
            call_user_func($ensure, $id);
        } elseif (is_callable($defaults)) {
            update_post_meta($id, self::SETTINGS, (array) call_user_func($defaults));
        }
        return $id;
    }

    /** What empty() calls non-empty, for a row's id. */
    private static function filled(mixed $value): bool
    {
        return ! in_array($value, [ null, false, 0, 0.0, '', '0', [] ], true);
    }
}
