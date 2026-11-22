<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage of the Product Feed Pro ops (issue #1096), as Product Feed Pro for
 * WooCommerce (woo-product-feed-pro, 13.5.x) keeps it: a feed is an
 * adt_product_feed post (published means active) with its state in adt_*
 * post meta (status, products_count, last_updated as "d M Y H:i:s" UTC,
 * history_products, last_run_exclusions, refresh_interval...); a product or
 * variation is left out of every feed by its _woosea_exclude_product meta
 * ("yes"); a feed parked after repeated crashed runs is listed in the
 * adt_pfp_adaptive_blocked_feeds option. A refresh is the plugin's own
 * adt_pfp_as_generate_product_feed Action Scheduler action, the one its
 * regenerate ability and scheduled refreshes queue.
 */
final class Product_Feed_Store
{
    public const POST_TYPE = 'adt_product_feed';

    public const REFRESH_HOOK = 'adt_pfp_as_generate_product_feed';

    private const META_PREFIX = 'adt_';

    private const EXCLUDE_META = '_woosea_exclude_product';

    private const BLOCKED_OPTION = 'adt_pfp_adaptive_blocked_feeds';

    /** A static method of the plugin's own classes, or null when it is not there. */
    private static function plugin_call(string $class, string $method, mixed ...$args): mixed
    {
        $callback = [ $class, $method ];
        return is_callable($callback) ? $callback(...$args) : null;
    }

    /** A function of Action Scheduler (shipped with WooCommerce), or null when it is not loaded. */
    private static function plugin_function(string $function, mixed ...$args): mixed
    {
        return function_exists($function) ? $function(...$args) : null;
    }

    /** @return list<\WP_Post> */
    public function feeds(?bool $active): array
    {
        $posts = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => null === $active ? [ 'publish', 'draft', 'private', 'pending' ] : ( $active ? [ 'publish' ] : [ 'draft', 'private', 'pending' ] ),
            'posts_per_page'   => 200,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        return array_values($posts);
    }

    public function feed(int $id): ?\WP_Post
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? $post : null;
    }

    /** One adt_ meta value of a feed, as the plugin stored it. */
    public function meta(int $id, string $key): mixed
    {
        return get_post_meta($id, self::META_PREFIX . $key, true);
    }

    /** The channel's name from the plugin's channel list, or the stored hash when the plugin cannot say. */
    public function channel(int $id): string
    {
        $hash = Typed::str($this->meta($id, 'channel_hash'));
        if ('' === $hash) {
            return '';
        }
        $channel = Typed::map(self::plugin_call('\\AdTribes\\PFP\\Helpers\\Product_Feed_Helper', 'get_channel_from_legacy_channel_hash', $hash));
        $name    = Typed::str($channel['name'] ?? null);
        return '' === $name ? $hash : $name;
    }

    /** The feed file's URL as the plugin builds it, or '' when the plugin cannot say. */
    public function file_url(int $id): string
    {
        return Typed::str(self::plugin_call('\\AdTribes\\PFP\\Factories\\Product_Feed', 'get_file_url_from_name', $this->meta($id, 'file_name'), $this->meta($id, 'file_format')));
    }

    public function blocked(int $id): bool
    {
        $blocked = get_option(self::BLOCKED_OPTION, []);
        return is_array($blocked) && isset($blocked[ $id ]);
    }

    /** @return array{total: int, products: list<array{id: int, name: string, sku: string, type: string, status: string}>} */
    public function excluded_products(int $page_size, int $offset): array
    {
        $query = Readable_Posts::query([
            'post_type'      => [ 'product', 'product_variation' ],
            'post_status'    => 'any',
            'posts_per_page' => $page_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the plugin keeps the exclusion flag only in this meta key.
            'meta_query'     => [ [ 'key' => self::EXCLUDE_META, 'value' => 'yes' ] ],
        ]);
        $rows = [];
        foreach (Typed::list($query->posts) as $post) {
            $id     = $post instanceof \WP_Post ? $post->ID : Typed::int($post);
            $rows[] = [
                'id'     => $id,
                'name'   => get_the_title($id),
                'sku'    => Typed::str(get_post_meta($id, '_sku', true)),
                'type'   => Typed::str(get_post_type($id)),
                'status' => Typed::str(get_post_status($id)),
            ];
        }
        return [ 'total' => $query->found_posts, 'products' => $rows ];
    }

    /** Queue the plugin's own feed generation for one feed; the action id (0 without Action Scheduler). */
    public function schedule_refresh(int $id): int
    {
        return Typed::int(self::plugin_function('as_schedule_single_action', time() + 1, self::REFRESH_HOOK, [ 'feed_id' => $id ]));
    }
}
