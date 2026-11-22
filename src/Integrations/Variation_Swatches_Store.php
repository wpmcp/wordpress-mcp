<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage of the Variation Swatches ops (issue #1096). The swatch type of a
 * global attribute is WooCommerce's own attribute_type column of
 * woocommerce_attribute_taxonomies; Variation Swatches for WooCommerce
 * (woo-variation-swatches, 2.6.x) only adds its types (color, image, button,
 * radio) to WooCommerce's list through product_attributes_type_selector and
 * keeps a term's colour and image in the product_attribute_color and
 * product_attribute_image term meta. Reads go through WooCommerce's
 * attribute functions, the one write through wc_update_attribute(), so
 * WooCommerce's own caches and woocommerce_attribute_updated follow it.
 */
final class Variation_Swatches_Store
{
    public const TABLE = 'woocommerce_attribute_taxonomies';

    /** The types WooCommerce itself treats as a plain dropdown. */
    private const PLAIN_TYPES = [ 'select', 'text' ];

    private const MAX_TERMS = 200;

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . self::TABLE;
    }

    /** A WooCommerce function this store calls, or null before WooCommerce loads. */
    private static function wc(string $function, mixed ...$args): mixed
    {
        return function_exists($function) ? $function(...$args) : null;
    }

    /** @return array<string, string> type => label, as WooCommerce offers them now. */
    public function types(): array
    {
        $out = [];
        foreach (Typed::map(self::wc('wc_get_attribute_types')) as $type => $label) {
            $out[ $type ] = Typed::str($label, $type);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function attributes(): array
    {
        $rows = [];
        foreach (Typed::list(self::wc('wc_get_attribute_taxonomies')) as $row) {
            $rows[] = $this->shape(Typed::map($row));
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function attribute(int $id): ?array
    {
        foreach (Typed::list(self::wc('wc_get_attribute_taxonomies')) as $row) {
            $row = Typed::map($row);
            if (Typed::int($row['attribute_id'] ?? null) === $id) {
                return $this->shape($row);
            }
        }
        return null;
    }

    /** @return list<array{id: int, name: string, slug: string, color: ?string, image_id: ?int}> */
    public function terms(string $taxonomy): array
    {
        if (! taxonomy_exists($taxonomy)) {
            return [];
        }
        $terms = get_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => self::MAX_TERMS ]);
        if (! is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            $color = Typed::str(get_term_meta($term->term_id, 'product_attribute_color', true));
            $image = Typed::int(get_term_meta($term->term_id, 'product_attribute_image', true));
            $out[] = [
                'id'       => $term->term_id,
                'name'     => $term->name,
                'slug'     => $term->slug,
                'color'    => '' === $color ? null : $color,
                'image_id' => $image > 0 ? $image : null,
            ];
        }
        return $out;
    }

    /**
     * Change one attribute's type through WooCommerce, every other column
     * passed back as it is so nothing else changes.
     */
    public function set_type(int $id, string $type): void
    {
        $current = self::wc('wc_get_attribute', $id);
        if (! is_object($current)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('attribute_not_found', sprintf('%d is not a WooCommerce product attribute.', $id));
        }
        $result = self::wc('wc_update_attribute', $id, [
            'name'         => Typed::str(Typed::field($current, 'name')),
            'slug'         => (string) preg_replace('/^pa_/', '', Typed::str(Typed::field($current, 'slug'))),
            'type'         => $type,
            'order_by'     => Typed::str(Typed::field($current, 'order_by')),
            'has_archives' => Typed::bool(Typed::field($current, 'has_archives')),
        ]);
        if ($result instanceof \WP_Error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('attribute_update_failed', $result->get_error_message());
        }
    }

    /**
     * After rows of the table were put back by an undo: drop WooCommerce's
     * cached copy of them and announce each row that differs from that copy
     * with woocommerce_attribute_updated, as wc_update_attribute() does, so
     * the swatches plugin and WooCommerce drop what they derived from it.
     * With no cached copy to compare, every row is announced.
     */
    public function refresh_after_restore(): void
    {
        $cached = get_transient('wc_attribute_taxonomies');
        $known  = [];
        foreach (Typed::list($cached) as $row) {
            $row                                              = Typed::map($row);
            $known[ Typed::int($row['attribute_id'] ?? null) ] = array_map([ Typed::class, 'str' ], $row);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the rows an undo just restored, compared with WooCommerce's cached copy; the cache is what is being refreshed.
        $rows = Typed::list($this->db->get_results($this->db->prepare('SELECT * FROM %i ORDER BY attribute_id', $this->table()), ARRAY_A));

        delete_transient('wc_attribute_taxonomies');
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }

        foreach ($rows as $row) {
            $row = array_map([ Typed::class, 'str' ], Typed::map($row));
            $id  = Typed::int($row['attribute_id'] ?? null);
            if (is_array($cached) && isset($known[ $id ]) && $known[ $id ] === $row) {
                continue;
            }
            $data = array_intersect_key($row, array_flip([ 'attribute_label', 'attribute_name', 'attribute_type', 'attribute_orderby', 'attribute_public' ]));
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own action, fired as wc_update_attribute() fires it.
            do_action('woocommerce_attribute_updated', $id, $data, $known[ $id ]['attribute_name'] ?? ($row['attribute_name'] ?? ''));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $types    = $this->types();
        $type     = Typed::str($row['attribute_type'] ?? null);
        $name     = Typed::str($row['attribute_name'] ?? null);
        $taxonomy = Typed::str(self::wc('wc_attribute_taxonomy_name', $name), 'pa_' . $name);
        $count    = taxonomy_exists($taxonomy) ? wp_count_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => false ]) : 0;
        return [
            'id'           => Typed::int($row['attribute_id'] ?? null),
            'name'         => Typed::str($row['attribute_label'] ?? null),
            'slug'         => $name,
            'taxonomy'     => $taxonomy,
            'type'         => $type,
            'type_label'   => $types[ $type ] ?? $type,
            'swatch'       => ! in_array($type, self::PLAIN_TYPES, true),
            'terms'        => Typed::int($count),
            'order_by'     => Typed::str($row['attribute_orderby'] ?? null),
            'has_archives' => Typed::bool($row['attribute_public'] ?? null),
        ];
    }
}
