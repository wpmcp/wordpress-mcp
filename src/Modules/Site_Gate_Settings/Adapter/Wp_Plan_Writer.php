<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Integrations\Support\Option_Sets;
use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot handling and the write itself for a Write_Plan (issue #1099).
 *
 * The snapshot covers exactly what the plan touches: an option_set over the
 * plugin options it names, or a post snapshot of the one SeedProd page whose
 * builder JSON it rewrites. The write stores each option as planned and puts
 * the page JSON in place with one column update, so the builder's HTML, the
 * page's dates and its revisions are left alone.
 */
#[Provides(Plan_Writer::class)]
final class Wp_Plan_Writer implements Plan_Writer
{
    private const UNSET = '__wpmcp_site_gate_unset__';

    public function __construct(private readonly Option_Sets $option_sets)
    {
    }

    /** @return array{object_type: string, object_id: int|string}|null */
    public function target(Write_Plan $plan): ?array
    {
        if (null !== $plan->post_id) {
            return ['object_type' => 'post', 'object_id' => $plan->post_id];
        }
        if ([] === $plan->options) {
            return null;
        }
        return ['object_type' => 'option_set', 'object_id' => $this->option_sets->id(array_keys($plan->options))];
    }

    public function changes(Write_Plan $plan): bool
    {
        if (null !== $plan->post_id) {
            return get_post_field('post_content_filtered', $plan->post_id, 'raw') !== (string) $plan->post_json;
        }
        foreach ($plan->options as $name => $value) {
            $current = get_option($name, self::UNSET);
            if (self::UNSET === $current || maybe_serialize($current) !== maybe_serialize($value)) {
                return true;
            }
        }
        return false;
    }

    public function apply(Write_Plan $plan): void
    {
        foreach ($plan->options as $name => $value) {
            update_option($name, $value);
        }
        if (null === $plan->post_id) {
            return;
        }
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column of one page; the cache is cleared right after.
        $wpdb->update($wpdb->posts, ['post_content_filtered' => (string) $plan->post_json], ['ID' => $plan->post_id], ['%s'], ['%d']);
        clean_post_cache($plan->post_id);
    }
}
