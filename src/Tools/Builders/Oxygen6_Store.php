<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage adapter for the Oxygen 6 writes of issue #805: every row they read
 * or write, in the shape the Oxygen 6.1 source keeps it.
 *
 * - A post's layout is the `_oxygen_data` row (read and written through
 *   Breakdance_Content, which refreshes Oxygen's CSS cache).
 * - A template's display conditions are `_oxygen_template_settings`. Oxygen's
 *   saveTemplate() hands its set_meta() the settings as a JSON string, and
 *   set_meta() JSON-encodes that string once more, so the row is a JSON
 *   string literal; getTemplateSettingsFromDatabase() decodes it twice.
 * - The design library's variables and selectors are the documents
 *   Breakdance_Design reads from Oxygen's options.
 *
 * Template kinds map to Oxygen's own post types (its themeless constants).
 */
final class Oxygen6_Store
{
    public const SETTINGS_KEY = '_oxygen_template_settings';

    /** Kind => Oxygen post type, for the kinds whose tree a library item can hold. */
    public const KINDS = [
        'template' => 'oxygen_template',
        'header'   => 'oxygen_header',
        'footer'   => 'oxygen_footer',
        'block'    => 'oxygen_block',
        'part'     => 'oxygen_part',
    ];

    /** The kinds that carry display conditions. */
    public const CONDITION_KINDS = ['template', 'header', 'footer'];

    public function post(int $post_id): ?\WP_Post
    {
        $post = $post_id > 0 ? get_post($post_id) : null;

        return $post instanceof \WP_Post ? $post : null;
    }

    /** The kind ('template', 'header', ...) of an Oxygen template post type, or null. */
    public function kind_of(string $post_type): ?string
    {
        $kind = array_search($post_type, self::KINDS, true);

        return false === $kind ? null : $kind;
    }

    public function document(int $post_id): ?object
    {
        return Breakdance_Content::get_document($post_id, 'oxygen');
    }

    /**
     * The `_oxygen_data` row decoded the way Oxygen's get_meta() decodes it
     * (an array holding tree_json_string), or null when there is none.
     *
     * @return array<string,mixed>|null
     */
    public function data_row(int $post_id): ?array
    {
        $raw     = get_post_meta($post_id, Oxygen_Page::DATA_KEY, true);
        $decoded = is_string($raw) && '' !== $raw ? json_decode($raw, true) : null;

        return is_array($decoded) && is_string($decoded['tree_json_string'] ?? null) ? Oxygen6_Values::map($decoded) : null;
    }

    public function has_layout(int $post_id): bool
    {
        return Oxygen_Page::has_layout($post_id);
    }

    public function layout_hash(int $post_id): string
    {
        return Oxygen_Page::hash($post_id);
    }

    public function save_document(int $post_id, object $doc): void
    {
        Breakdance_Content::save($post_id, $doc, 'oxygen');
    }

    /** The stored settings row as it is, '' when absent. */
    public function settings_raw(int $post_id): string
    {
        $raw = get_post_meta($post_id, self::SETTINGS_KEY, true);

        return is_string($raw) ? $raw : '';
    }

    /**
     * The settings as getTemplateSettingsFromDatabase() reads them.
     *
     * @return array<string,mixed>|null
     */
    public function settings(int $post_id): ?array
    {
        $value = json_decode($this->settings_raw($post_id), true);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? Oxygen6_Values::map($value) : null;
    }

    /** sha256 of the stored settings row, the hash a conditions write is pinned to. */
    public function settings_hash(int $post_id): string
    {
        return hash('sha256', $this->settings_raw($post_id));
    }

    /**
     * Store settings the way saveTemplate() does: the JSON string, encoded
     * once more (a null is stored as the string "null", as Oxygen's import
     * stores a Component's missing settings).
     *
     * @param array<string,mixed>|null $settings
     */
    public function save_settings(int $post_id, ?array $settings): void
    {
        update_post_meta($post_id, self::SETTINGS_KEY, wp_slash((string) wp_json_encode((string) wp_json_encode($settings))));
    }

    /**
     * Insert a new post. Every field is new input, so all of it passes the
     * usual save filters.
     *
     * @param array{post_title:string,post_type:string,post_status:string,post_name?:string,post_content?:string,post_excerpt?:string} $fields
     */
    public function insert_post(array $fields): int
    {
        $id = wp_insert_post(wp_slash($fields + ['post_content' => '']), true);
        if (is_wp_error($id)) {
            throw new \RuntimeException(esc_html($id->get_error_message()));
        }

        return $id;
    }

    /**
     * Remove posts this same call created a moment ago, when it cannot finish.
     *
     * @param int[] $post_ids
     */
    public function remove_created(array $post_ids): void
    {
        foreach (array_reverse($post_ids) as $post_id) {
            wp_delete_post($post_id, true);
        }
    }

    /**
     * Published posts of a type with this exact title, as Oxygen's
     * getIdOfPostByTitle() looks them up before importing.
     *
     * @return int[]
     */
    public function published_with_title(string $post_type, string $title): array
    {
        $query = new \WP_Query([
            'post_type'              => $post_type,
            'title'                  => $title,
            'post_status'            => 'publish',
            'posts_per_page'         => 20,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ]);

        $ids = [];
        foreach ($query->posts ?? [] as $post) {
            $ids[] = $post instanceof \WP_Post ? $post->ID : $post;
        }

        return $ids;
    }

    /**
     * The variables and selectors documents ({variables|selectors, collections}).
     *
     * @return array{variables:array<string,mixed>,selectors:array<string,mixed>}
     */
    public function design(): array
    {
        $design = Breakdance_Design::design_system('oxygen');

        return [
            'variables' => Oxygen6_Values::map($design['variables'] ?? null),
            'selectors' => Oxygen6_Values::map($design['classes'] ?? null),
        ];
    }

    /** The address Oxygen's import rewrites source links to (get_site_url()). */
    public function site_url(): string
    {
        return get_site_url();
    }
}
