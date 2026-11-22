<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Envira Gallery's storage (issue #705), as Envira Gallery Lite 1.17 keeps
 * it (Envira Gallery Pro shares the post type and meta).
 *
 * A gallery is a non-public `envira` post behind Envira's own capabilities
 * (includes/global/posttype.php). Its data is the _eg_gallery_data meta:
 * id, gallery (attachment id => item, in display order: status, src,
 * title, link, alt, caption, thumb) and config (Envira_Gallery_Common
 * get_config_defaults(), with title and slug). Membership is kept twice
 * more: the gallery's _eg_in_gallery meta lists its attachment ids, and
 * each attachment's _eg_has_gallery meta lists its galleries
 * (includes/admin/ajax.php, includes/services/GalleryService.php). Envira
 * caches the rendered data in _eg_cache_* transients, dropped by
 * Envira_Gallery_Common::flush_gallery_caches().
 *
 * Only storage lives here; Envira_Operations validates and dispatches.
 */
final class Envira_Store
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const GALLERY = 'envira';

    public const DATA = '_eg_gallery_data';

    public const IN_GALLERY = '_eg_in_gallery';

    public const HAS_GALLERY = '_eg_has_gallery';

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
        $posts = [];
        foreach ($q->posts ?? [] as $post) {
            if ($post instanceof \WP_Post) {
                $posts[] = $post;
            }
        }
        return [ 'galleries' => $posts, 'total' => $q->found_posts ];
    }

    /** @return array<string, mixed> */
    public function data(int $id): array
    {
        $raw             = get_post_meta($id, self::DATA, true);
        $data            = is_array($raw) ? Typed::map($raw) : [];
        $data['gallery'] = isset($data['gallery']) && is_array($data['gallery']) ? $data['gallery'] : [];
        return $data;
    }

    /** @return array<int|string, mixed> The gallery's items (attachment id => item, display order). */
    public function items(int $id): array
    {
        $items = $this->data($id)['gallery'];
        return is_array($items) ? $items : [];
    }

    /** @return list<int> */
    public function image_ids(int $id): array
    {
        return Typed::ints(array_keys($this->items($id)));
    }

    /**
     * The galleries an attachment names in its _eg_has_gallery meta.
     *
     * @return list<int>
     */
    public function galleries_of(int $image): array
    {
        return Typed::ints(get_post_meta($image, self::HAS_GALLERY, true));
    }

    /** Add or drop one gallery from an attachment's _eg_has_gallery, as Envira's own handlers do. */
    public function set_membership(int $image, int $gallery, bool $member): void
    {
        $now = $this->galleries_of($image);
        $new = $member ? array_values(array_unique(array_merge($now, [ $gallery ]))) : array_values(array_diff($now, [ $gallery ]));
        update_post_meta($image, self::HAS_GALLERY, $new);
    }

    /**
     * The item an attachment joins a gallery with, as GalleryService::add_images() builds it.
     *
     * @return array{status: string, src: string, title: string, link: string, alt: string, caption: string, thumb: string}
     */
    public function new_item(int $image): array
    {
        $attachment = get_post($image);
        $src        = esc_url_raw((string) wp_get_attachment_url($image));
        $alt        = Typed::str(get_post_meta($image, '_wp_attachment_image_alt', true));
        return [
            'status'  => 'active',
            'src'     => $src,
            'title'   => wp_kses_post($attachment instanceof \WP_Post ? $attachment->post_title : ''),
            'link'    => $src,
            'alt'     => '' !== $alt && '0' !== $alt ? sanitize_text_field($alt) : '',
            'caption' => wp_kses_post($attachment instanceof \WP_Post ? $attachment->post_excerpt : ''),
            'thumb'   => '',
        ];
    }

    /**
     * Store a gallery's items (attachment id => item, display order) and its
     * _eg_in_gallery list, fire Envira's save action and flush its caches,
     * as GalleryService::save_data() does.
     *
     * @param array<int|string, mixed> $items
     */
    public function save_items(int $id, array $items): void
    {
        $data               = $this->data($id);
        $data['id']         = $id;
        $data['gallery']    = $items;
        $data['in_gallery'] = Typed::ints(array_keys($items));
        update_post_meta($id, self::DATA, wp_slash($data));
        update_post_meta($id, self::IN_GALLERY, $data['in_gallery']);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing Envira's own save action, as its GalleryService does after a save; wpmcp adds no callback to it.
        do_action('envira_gallery_saved_settings', $data, $id, get_post($id));
        $this->flush($id);
    }

    /** Drop Envira's cached copies of a gallery (by id and by slug). */
    public function flush(int $id): void
    {
        $post = get_post($id);
        $data = $this->data($id);
        $slug   = $post instanceof \WP_Post && '' !== $post->post_name ? $post->post_name : Typed::str(Typed::field($data['config'] ?? null, 'slug'));
        $common = self::common();
        if (null !== $common && method_exists($common, 'flush_gallery_caches')) {
            $common->flush_gallery_caches($id, $slug);
        } else {
            $keys = [ '_eg_cache_id_' . $id, '_eg_cache_' . $id, '_eg_cache_all' ];
            if ('' !== $slug) {
                $keys[] = '_eg_cache_slug_' . $slug;
                $keys[] = '_eg_cache_' . $slug;
            }
            foreach ($keys as $key) {
                delete_transient($key);
            }
        }
        clean_post_cache($id);
    }

    /**
     * Mark a gallery inactive when it is trashed and active again when it
     * is restored, as Envira's own trash handlers do (GalleryService
     * mark_trashed(), Envira_Gallery_Common_Admin untrash_gallery()); its
     * admin-only hooks miss a trash made outside wp-admin. A no-op when the
     * flag already says so.
     */
    public function set_trashed(int $id, bool $trashed): void
    {
        $data = get_post_meta($id, self::DATA, true);
        if (! is_array($data) || ('inactive' === ($data['status'] ?? '')) === $trashed) {
            return;
        }
        if ($trashed) {
            $data['status'] = 'inactive';
        } else {
            unset($data['status']);
        }
        update_post_meta($id, self::DATA, wp_slash($data));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- firing Envira's own envira_gallery_trash or envira_gallery_untrash action, as its handlers do; wpmcp adds no callback to it.
        do_action($trashed ? 'envira_gallery_trash' : 'envira_gallery_untrash', $id, $data);
        $this->flush($id);
    }

    /** Insert a gallery as GalleryService::create() does: the post, then its data with Envira's config defaults. */
    public function insert_gallery(string $title, string $status): int
    {
        $id = wp_insert_post(wp_slash([
            'post_type'   => self::GALLERY,
            'post_title'  => $title,
            'post_status' => $status,
            'post_author' => get_current_user_id(),
        ]), true);
        if (is_wp_error($id)) {
            throw new Operation_Refused('insert_failed', $id->get_error_message());
        }
        $common   = self::common();
        $defaults = null !== $common && method_exists($common, 'get_config_defaults') ? Typed::map($common->get_config_defaults($id)) : [];
        if ([] === $defaults) {
            // title is added below.
            $defaults = [ 'type' => 'default', 'layout' => 'automatic', 'columns' => '4', 'slug' => '' ];
        }
        $defaults['title'] = $title;
        update_post_meta($id, self::DATA, wp_slash([ 'id' => $id, 'gallery' => [], 'in_gallery' => [], 'config' => $defaults ]));
        return $id;
    }

    /** Envira_Gallery_Common's instance when Envira runs, else null. */
    private static function common(): ?object
    {
        $class = 'Envira_Gallery_Common';
        if (! class_exists($class) || ! is_callable([ $class, 'get_instance' ])) {
            return null;
        }
        $instance = call_user_func([ $class, 'get_instance' ]);
        return is_object($instance) ? $instance : null;
    }
}
