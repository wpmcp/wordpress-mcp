<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Schema templates (issue #681), read only, paid tier, on the theme pair:
 *  - rankmath-list-schema-templates: Rank Math Pro's schema templates, the
 *    rank_math_schema posts, each with the schema types of its
 *    rank_math_schema_{Type} meta and the display conditions in that
 *    schema's metadata;
 *  - yoast-get-schema-defaults: Yoast SEO's per post type schema defaults,
 *    the schema-page-type-{type} and schema-article-type-{type} settings in
 *    wpseo_titles (Yoast has no templates of its own).
 */
final class Seo_Schema_Template_Ops
{
    private const MAX_PAGE = 100;

    public static function rank_math_pro_active(): bool
    {
        return (bool) apply_filters('wpmcp_rank_math_pro_active', defined('RANK_MATH_PRO_VERSION'));
    }

    public static function yoast_active(): bool
    {
        return (bool) apply_filters('wpmcp_yoast_active', defined('WPSEO_VERSION'));
    }

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $base = [ 'tier' => 'pro', 'mode' => 'read', 'capability' => 'manage_options' ];

        return [
            'rankmath-list-schema-templates' => $base + [
                'description'  => 'List Rank Math Pro schema templates: id, title, status, the schema types each defines and its display conditions; paged',
                'requires'     => static fn () => self::rank_math_pro_active() ? true : Plugin_Refusal::make('rank_math_pro_inactive', 'Rank Math Pro', 'seo-by-rank-math-pro/rank-math-pro.php'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->rank_math_templates($args),
            ],
            'yoast-get-schema-defaults'      => $base + [
                'description'  => 'Yoast SEO schema defaults per public post type: the default page type and article type (Settings > Content types > Schema)',
                'requires'     => static fn () => self::yoast_active() ? true : Plugin_Refusal::make('yoast_inactive', 'Yoast SEO', 'wordpress-seo/wp-seo.php'),
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (): array => $this->yoast_defaults(),
            ],
        ];
    }

    private function rank_math_templates(array $args): array
    {
        $per   = (int) ($args['per_page'] ?? 50);
        $page  = (int) ($args['page'] ?? 1);
        $query = new \WP_Query([
            'post_type'      => 'rank_math_schema',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => $per,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($query->posts as $post) {
            $types      = [];
            $conditions = [];
            foreach ((array) get_post_meta((int) $post->ID) as $key => $values) {
                if (! str_starts_with((string) $key, 'rank_math_schema_')) {
                    continue;
                }
                $schema  = maybe_unserialize($values[0] ?? '');
                $types[] = is_array($schema) && isset($schema['@type']) ? (string) $schema['@type'] : substr((string) $key, strlen('rank_math_schema_'));
                if (is_array($schema) && is_array($schema['metadata']['displayConditions'] ?? null)) {
                    $conditions = array_merge($conditions, array_values($schema['metadata']['displayConditions']));
                }
            }
            $out[] = [
                'id'                 => (int) $post->ID,
                'title'              => (string) $post->post_title,
                'status'             => (string) $post->post_status,
                'schema_types'       => $types,
                'display_conditions' => $conditions,
            ];
        }
        return [ 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $per, 'templates' => $out ];
    }

    private function yoast_defaults(): array
    {
        $titles = (array) get_option('wpseo_titles', []);
        $rows   = [];
        foreach (get_post_types([ 'public' => true ], 'names') as $type) {
            if ('attachment' === $type) {
                continue;
            }
            $rows[] = [
                'post_type'    => (string) $type,
                'page_type'    => (string) ($titles[ 'schema-page-type-' . $type ] ?? ''),
                'article_type' => (string) ($titles[ 'schema-article-type-' . $type ] ?? ''),
            ];
        }
        return [ 'post_types' => $rows ];
    }
}
