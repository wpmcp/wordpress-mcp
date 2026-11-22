<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Product Feed Pro ops (issue #1096) for the plugin-data pair:
 *
 *  - productfeed-list-feeds: every feed with its active flag, status
 *    (ready, processing, stopped, error, not run yet), channel, file, last
 *    run, products in the catalogue and in the last file, products the last
 *    run left out, refresh interval, and whether the plugin parked it after
 *    repeated crashes;
 *  - productfeed-get-feed: one feed with the last run's left-out products
 *    per reason;
 *  - productfeed-list-excluded-products: the products and variations
 *    excluded from every feed by hand;
 *  - productfeed-refresh-feed: queue the plugin's own regeneration of one
 *    active feed. It rewrites the feed file and cannot be undone, so it needs
 *    confirm:true in its arguments; off until
 *    wpmcp_enable_product_feed_write. A feed that is already running is
 *    refused.
 *
 * Every op takes manage_woocommerce, the capability the plugin's own
 * feed screens and abilities ask for.
 */
final class Product_Feed_Operations
{
    public const CAPABILITY = 'manage_woocommerce';

    public const OPT_IN_FILTER = 'wpmcp_enable_product_feed_write';

    private const MAX_PAGE_SIZE = 100;

    /**
     * @param \Closure(): (true|array<string, mixed>) $presence The plugin check every op declares.
     */
    public function __construct(
        private readonly Product_Feed_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $feed    = [ 'type' => 'object', 'properties' => [ 'feed_id' => $id ], 'required' => [ 'feed_id' ] ];
        $base    = [ 'capability' => self::CAPABILITY, 'requires' => $this->presence ];
        $enabled = Typed::bool(apply_filters('wpmcp_enable_product_feed_write', false));

        return [
            'productfeed-list-feeds'             => $base + [
                'mode'         => 'read',
                'description'  => 'List Product Feed Pro feeds: active, status (ready, processing, stopped, error), channel, file URL, last run (UTC), products in the catalogue and in the last file, products the last run left out, refresh interval. active filters',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'active' => [ 'type' => 'boolean' ] ] ],
                'handler'      => fn (array $args): array => [
                    'feeds'          => array_map(fn (\WP_Post $post): array => $this->summary($post), $this->store->feeds(isset($args['active']) ? Typed::bool($args['active']) : null)),
                    'writes_enabled' => $enabled,
                ],
            ],
            'productfeed-get-feed'               => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Product Feed Pro feed with the products its last run left out, per reason (filters and rules, manual exclusions, not sold, catalog visibility...)',
                'input_schema' => $feed,
                'validate'     => fn (array $args): ?array => $this->feed_refusal(Typed::int($args['feed_id'] ?? null)),
                'handler'      => fn (array $args): array => [ 'feed' => $this->detail(Typed::int($args['feed_id'] ?? null)) ],
            ],
            'productfeed-list-excluded-products' => $base + [
                'mode'         => 'read',
                'description'  => 'List the products and variations excluded from every Product Feed Pro feed by hand (the product\'s "Exclude from feeds" box). page_size (default 50, max 100) and offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->store->excluded_products(Typed::int($args['page_size'] ?? null, 50), Typed::int($args['offset'] ?? null)),
            ],
            'productfeed-refresh-feed'           => $base + [
                'mode'               => 'destructive',
                'undoable'           => false,
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Regenerate one active Product Feed Pro feed now, through the plugin\'s own background generation. Rewrites the feed file; cannot be undone. Needs confirm:true. Disabled by default (wpmcp_enable_product_feed_write filter)',
                'input_schema'       => $feed,
                'validate'           => fn (array $args): ?array => $this->refresh_refusal(Typed::int($args['feed_id'] ?? null)),
                'confirm_preview'    => fn (array $args): array => $this->refresh_preview(Typed::int($args['feed_id'] ?? null)),
                'handler'            => fn (array $args): array => $this->refresh(Typed::int($args['feed_id'] ?? null)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(\WP_Post $post): array
    {
        $id      = $post->ID;
        $history = Typed::map($this->store->meta($id, 'history_products'));
        $latest  = [] === $history ? null : end($history);
        $count   = is_array($latest) ? reset($latest) : $latest;
        $status  = Typed::str($this->store->meta($id, 'status'));
        return [
            'id'                => $id,
            'title'             => $post->post_title,
            'active'            => 'publish' === $post->post_status,
            'status'            => '' === $status ? 'not run yet' : $status,
            'error'             => 'error' === $status,
            'blocked'           => $this->store->blocked($id),
            'channel'           => $this->store->channel($id),
            'file_format'       => Typed::str($this->store->meta($id, 'file_format')),
            'file_url'          => $this->store->file_url($id),
            'last_run'          => self::utc(Typed::str($this->store->meta($id, 'last_updated'))),
            'products_count'    => Typed::int($this->store->meta($id, 'products_count')),
            'products_in_feed'  => is_numeric($count) ? (int) $count : null,
            'excluded_last_run' => array_sum(array_column($this->exclusions($id), 'count')),
            'refresh_interval'  => Typed::str($this->store->meta($id, 'refresh_interval')),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $post    = $this->store->feed($id);
        $last    = Typed::map($this->store->meta($id, 'last_run_exclusions'));
        $summary = null === $post ? [ 'id' => $id ] : $this->summary($post);
        return $summary + [
            'exclusions'       => $this->exclusions($id),
            'products_read'    => isset(Typed::map($last['counts'] ?? null)['walked']) ? Typed::int(Typed::map($last['counts'] ?? null)['walked']) : null,
            'last_run_started' => Typed::int($last['products_count'] ?? null),
            'processed'        => Typed::int($this->store->meta($id, 'total_products_processed')),
        ];
    }

    /** @return list<array{reason: string, label: string, count: int}> */
    private function exclusions(int $id): array
    {
        $last   = Typed::map($this->store->meta($id, 'last_run_exclusions'));
        $labels = self::labels();
        $out    = [];
        foreach (Typed::map($last['counts'] ?? null) as $reason => $count) {
            if ('walked' === $reason || Typed::int($count) <= 0) {
                continue;
            }
            $out[] = [ 'reason' => $reason, 'label' => $labels[ $reason ] ?? $reason, 'count' => Typed::int($count) ];
        }
        return $out;
    }

    /** @return array<string, string> The plugin's own reason labels, when it is loaded. */
    private static function labels(): array
    {
        $callback = [ '\\AdTribes\\PFP\\Factories\\Product_Feed', 'get_exclusion_labels' ];
        $out      = [];
        foreach (Typed::map(is_callable($callback) ? $callback() : null) as $reason => $label) {
            $out[ $reason ] = Typed::str($label);
        }
        return $out;
    }

    /** The plugin's "d M Y H:i:s" UTC stamp as ISO 8601, or null before the first run. */
    private static function utc(string $stamp): ?string
    {
        $time = '' === $stamp ? false : strtotime($stamp . ' UTC');
        return false === $time ? null : gmdate('c', $time);
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function feed_refusal(int $id): ?array
    {
        return null === $this->store->feed($id)
            ? [ 'code' => 'feed_not_found', 'message' => sprintf('%d is not a Product Feed Pro feed.', $id), 'data' => [ 'feed_id' => $id ] ]
            : null;
    }

    /** @return array{code: string, message: string, data?: array<string, int>}|null */
    private function refresh_refusal(int $id): ?array
    {
        $refusal = $this->feed_refusal($id);
        if (null !== $refusal) {
            return $refusal;
        }
        $post = $this->store->feed($id);
        if (null !== $post && 'publish' !== $post->post_status) {
            return [ 'code' => 'feed_inactive', 'message' => sprintf('Feed %d is not active; activate it on the Product Feed Pro screen first.', $id), 'data' => [ 'feed_id' => $id ] ];
        }
        if ('processing' === Typed::str($this->store->meta($id, 'status'))) {
            return [ 'code' => 'feed_running', 'message' => sprintf('Feed %d is being generated now; wait for this run to finish.', $id), 'data' => [ 'feed_id' => $id ] ];
        }
        return null;
    }

    /** @return array{message: string, data: array<string, mixed>} */
    private function refresh_preview(int $id): array
    {
        $post = $this->store->feed($id);
        return [
            'message' => sprintf(
                'It regenerates Product Feed Pro feed "%s" (%d) in the background, rewriting its %s file from the current catalogue (%d products). This cannot be undone.',
                null === $post ? '' : $post->post_title,
                $id,
                Typed::str($this->store->meta($id, 'file_format')),
                Typed::int($this->store->meta($id, 'products_count'))
            ),
            'data'    => [ 'feed_id' => $id ],
        ];
    }

    /** @return array<string, mixed> */
    private function refresh(int $id): array
    {
        $action = $this->store->schedule_refresh($id);
        return [
            'feed_id'   => $id,
            'scheduled' => $action > 0,
            'action_id' => $action,
            'check'     => 'Read the feed again with productfeed-get-feed: its status turns processing and then ready, with a new last_run.',
        ];
    }
}
