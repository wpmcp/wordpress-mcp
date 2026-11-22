<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MetaSlider's storage (issue #705), as MetaSlider 3.114 keeps it.
 *
 * A slider is a non-public `ml-slider` post (ml-slider.php) with its
 * settings in the ml-slider_settings meta (MetaSlider_Slideshow_Settings
 * defaults: type flex, 700 by 300). Its slides are non-public `ml-slide`
 * posts tagged with the `ml-slider` term named after the slider id, read by
 * term slug, status publish (or inherit for an attachment that is its own
 * slide, the pre-3.x layout) and menu_order ascending
 * (inc/slider/metaslider.class.php). An image slide keeps its image in
 * _thumbnail_id, its caption in post_excerpt, its link in ml-slider_url,
 * its target in ml-slider_new_window, its own title in ml-slider_title and
 * alt text in the slide's _wp_attachment_image_alt; the title and alt come
 * from the image while ml-slider_inherit_image_title / _alt are set
 * (inc/slide/metaslide.image.class.php save() and get_slide()).
 *
 * Only storage lives here; MetaSlider_Operations validates and dispatches.
 */
final class MetaSlider_Store
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const SLIDER = 'ml-slider';

    public const SLIDE = 'ml-slide';

    public const TAXONOMY = 'ml-slider';

    public const SETTINGS = 'ml-slider_settings';

    public const HIDDEN = '_meta_slider_slide_is_hidden';

    /** MetaSlider's own defaults for the settings this store sets on create. */
    public const DEFAULTS = [ 'type' => 'flex', 'width' => 700, 'height' => 300 ];

    public const TYPES = [ 'flex', 'coin', 'nivo', 'responsive' ];

    public function is_slider(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::SLIDER === $post->post_type && 'trash' !== $post->post_status;
    }

    /** The slider a slide is tagged to, or 0 when it is not a live slide. */
    public function slider_of(int $slide): int
    {
        $post = get_post($slide);
        if (! $post instanceof \WP_Post || self::SLIDE !== $post->post_type || 'trash' === $post->post_status) {
            return 0;
        }
        $terms = wp_get_object_terms($slide, self::TAXONOMY, [ 'fields' => 'names' ]);
        foreach (is_array($terms) ? $terms : [] as $name) {
            if (ctype_digit($name) && $this->is_slider((int) $name)) {
                return (int) $name;
            }
        }
        return 0;
    }

    /** @return array{sliders: list<\WP_Post>, total: int} */
    public function sliders(string $search, int $page, int $per_page): array
    {
        $query = [
            'post_type'      => self::SLIDER,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if ('' !== $search) {
            $query['s'] = $search;
        }
        $q       = Readable_Posts::query($query);
        $sliders = [];
        foreach ($q->posts ?? [] as $post) {
            if ($post instanceof \WP_Post) {
                $sliders[] = $post;
            }
        }
        return [ 'sliders' => $sliders, 'total' => $q->found_posts ];
    }

    /**
     * The slider's slides in display order, as MetaSlider reads them.
     *
     * @return list<int>
     */
    public function slide_ids(int $slider): array
    {
        $q = new \WP_Query([
            'post_type'        => [ 'attachment', self::SLIDE ],
            'post_status'      => [ 'inherit', 'publish' ],
            'orderby'          => 'menu_order',
            'order'            => 'ASC',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- mirrors MetaSlider's own get_slides(): a language filter (WPML) must not hide slides of the slider.
            'suppress_filters' => true,
            'tax_query'        => [ [ 'taxonomy' => self::TAXONOMY, 'field' => 'slug', 'terms' => (string) $slider ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- MetaSlider's own slide lookup.
        ]);
        $ids = [];
        foreach ($q->posts ?? [] as $post) {
            $ids[] = $post instanceof \WP_Post ? $post->ID : $post;
        }
        return $ids;
    }

    /** @return array<string, mixed> */
    public function settings(int $slider): array
    {
        return Typed::map(get_post_meta($slider, self::SETTINGS, true));
    }

    /** @return array<string, mixed> */
    public function slide(int $slide): array
    {
        $post       = get_post($slide);
        $attachment = $post instanceof \WP_Post && 'attachment' === $post->post_type;
        $image      = $attachment ? $slide : Typed::int(get_post_thumbnail_id($slide));
        $inherits   = static fn (string $field): bool => $attachment || (bool) get_post_meta($slide, 'ml-slider_inherit_image_' . $field, true);
        $type       = Typed::str(get_post_meta($slide, 'ml-slider_type', true));

        return [
            'id'         => $slide,
            'type'       => '' === $type ? 'image' : $type,
            'image_id'   => $image,
            'image_url'  => $image > 0 ? (string) wp_get_attachment_url($image) : '',
            'title'      => $inherits('title') ? get_the_title($image) : Typed::str(get_post_meta($slide, 'ml-slider_title', true)),
            'alt'        => Typed::str(get_post_meta($inherits('alt') ? $image : $slide, '_wp_attachment_image_alt', true)),
            'caption'    => $post instanceof \WP_Post ? $post->post_excerpt : '',
            'url'        => Typed::str(get_post_meta($slide, 'ml-slider_url', true)),
            'new_window' => (bool) get_post_meta($slide, 'ml-slider_new_window', true),
            'hidden'     => (bool) get_post_meta($slide, self::HIDDEN, true),
            'order'      => $post instanceof \WP_Post ? $post->menu_order : 0,
        ];
    }

    /**
     * Insert a slider as MetaSlider_Slideshows::create() does; the term is made with its first slide.
     *
     * @param array<string, mixed> $settings
     */
    public function insert_slider(string $title, array $settings): int
    {
        $id = wp_insert_post([
            'post_type'   => self::SLIDER,
            'post_title'  => wp_slash($title),
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id)) {
            throw new Operation_Refused('insert_failed', $id->get_error_message());
        }
        // MetaSlider's renderer reads every default key, so its own defaults come first while it is loaded.
        $defaults = self::DEFAULTS;
        if (class_exists('MetaSlider_Slideshow_Settings')) {
            $defaults = Typed::map(\MetaSlider_Slideshow_Settings::defaults());
        }
        add_post_meta($id, self::SETTINGS, array_merge($defaults, [ 'title' => $title ], $settings), true);
        $this->default_theme($id);
        return $id;
    }

    /** Give a new slider the free default theme, as MetaSlider_Slideshows::create() does without Pro. */
    private function default_theme(int $id): void
    {
        if (! class_exists('MetaSlider_Themes')) {
            return;
        }
        if (! function_exists('metaslider_pro_is_active') || (bool) metaslider_pro_is_active()) {
            return;
        }
        $themes = \MetaSlider_Themes::get_instance();
        if (! is_object($themes) || ! method_exists($themes, 'set') || ! method_exists($themes, 'get_single_theme')) {
            return;
        }
        $themes->set($id, $themes->get_single_theme('default-base'));
    }

    /**
     * Insert an image slide after the slider's last one, as
     * MetaImageSlide::add_slide() does: the slide post, its image, its
     * type, the inherit flags, its term and the next menu_order.
     */
    public function insert_image_slide(int $slider, int $image): int
    {
        $last = 0;
        foreach ($this->slide_ids($slider) as $existing) {
            $last = max($last, (int) get_post_field('menu_order', $existing));
        }
        $id = wp_insert_post([
            'post_type'   => self::SLIDE,
            'post_title'  => "Slider {$slider} - image",
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'menu_order'  => $last + 1,
        ], true);
        if (is_wp_error($id)) {
            throw new Operation_Refused('insert_failed', $id->get_error_message());
        }
        set_post_thumbnail($id, $image);
        update_post_meta($id, 'ml-slider_type', 'image');
        update_post_meta($id, 'ml-slider_inherit_image_title', true);
        update_post_meta($id, 'ml-slider_inherit_image_alt', true);
        $term = $this->term($slider);
        wp_set_object_terms($id, [ $term ], self::TAXONOMY, true);
        return $id;
    }

    /** The slider's term id, created as tag_slide_to_slider() creates it. */
    private function term(int $slider): int
    {
        $term = get_term_by('name', (string) $slider, self::TAXONOMY);
        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }
        $made = wp_insert_term((string) $slider, self::TAXONOMY);
        if (is_wp_error($made)) {
            throw new Operation_Refused('term_failed', $made->get_error_message());
        }
        return $made['term_id'];
    }

    public function set_order(int $slide, int $order): void
    {
        $done = Save_Filters::update_post([ 'ID' => $slide, 'menu_order' => $order ], true);
        if (is_wp_error($done)) {
            throw new Operation_Refused('update_failed', $done->get_error_message());
        }
    }

    /**
     * Change an image slide's fields as MetaImageSlide::save() stores them:
     * an empty link or a false window deletes its meta, and a title or alt
     * given here stops the slide inheriting the image's.
     *
     * @param array{caption?: string, url?: string, new_window?: bool, title?: string, alt?: string} $fields
     */
    public function update_slide(int $slide, array $fields): void
    {
        if (array_key_exists('caption', $fields)) {
            $done = Save_Filters::update_post(wp_slash([ 'ID' => $slide, 'post_excerpt' => $fields['caption'] ]), true);
            if (is_wp_error($done)) {
                throw new Operation_Refused('update_failed', $done->get_error_message());
            }
        }
        $meta = static function (string $key, string|bool $value) use ($slide): void {
            if (false === $value || '' === $value) {
                delete_post_meta($slide, $key);
                return;
            }
            update_post_meta($slide, $key, true === $value ? true : wp_slash($value));
        };
        if (array_key_exists('url', $fields)) {
            $meta('ml-slider_url', $fields['url']);
        }
        if (array_key_exists('new_window', $fields)) {
            $meta('ml-slider_new_window', $fields['new_window']);
        }
        if (array_key_exists('title', $fields)) {
            $meta('ml-slider_title', $fields['title']);
            update_post_meta($slide, 'ml-slider_inherit_image_title', false);
        }
        if (array_key_exists('alt', $fields)) {
            update_post_meta($slide, '_wp_attachment_image_alt', wp_slash($fields['alt']));
            update_post_meta($slide, 'ml-slider_inherit_image_alt', false);
        }
        clean_post_cache($slide);
    }
}
