<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\FunnelKit_Offer_Lists;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Funnel Builder Pro's upsell offers as it stores them (issue #1021), read
 * from that storage and changed only through Funnel Builder Pro itself.
 *
 * Storage, from Funnel Builder Pro 3.16's one-click upsell module: an
 * upsell step is a wfocu_funnel post in the funnel's steps JSON (type
 * wc_upsells) carrying _bwf_in_funnel; its offers are wfocu_offer posts
 * listed in order in the step's _funnel_steps meta (id, type upsell or
 * downsell, name, state) and linked back by _funnel_id. An offer's products
 * are in its _wfocu_setting object: products maps a hash key to a product
 * id, fields holds each key's quantity, discount_type and discount_amount
 * (and, for a variable product, its default variation), variations each
 * key's enabled variations.
 *
 * Creating, renaming, switching and deleting an offer go through Funnel
 * Builder Pro's own REST routes (funnel-upsell/{step}/offers and the free
 * funnel-offer/save-state/{offer}), in-process through FunnelKit_Writes,
 * so FunnelKit's own access check runs. Its offer product routes end in
 * wp_send_json(), which ends the PHP process when called in-process, so the
 * product write calls the upsell module's own WFOCU_Common methods the
 * routes call (get_offer, get_product_id_hash, update_offer,
 * update_funnel_time) and fires its wfocu_offer_updated action; the access
 * check those routes make is asked first (FunnelKit_Funnel_Snapshot::
 * current_user_may_edit_funnels()).
 */
final class FunnelKit_Upsell_Store
{
    public const OFFER_POST_TYPE = 'wfocu_offer';

    /** @var \Closure(string,string,array<string,mixed>,array<string,mixed>|null):array<string,mixed> */
    private readonly \Closure $rest;

    /** @param (\Closure(string,string,array<string,mixed>,array<string,mixed>|null):array<string,mixed>)|null $rest FunnelKit's REST routes, in-process. */
    public function __construct(?\Closure $rest = null)
    {
        $this->rest = $rest ?? \Closure::fromCallable([ FunnelKit_Writes::class, 'rest' ]);
    }

    // ---------------------------------------------------------------- where

    /** The funnel an upsell step belongs to, or 0 when it is not a listed wc_upsells step. */
    public function upsell_funnel(int $step_id): int
    {
        if (FunnelKit_Offer_Lists::UPSELL_POST_TYPE !== get_post_type($step_id)) {
            return 0;
        }
        $funnel_id = (int) get_post_meta($step_id, '_bwf_in_funnel', true);
        $row       = $funnel_id > 0 ? FunnelKit_Funnel_Snapshot::row($funnel_id) : null;
        return null !== $row && array_key_exists($step_id, FunnelKit_Offer_Lists::lists($row)) ? $funnel_id : 0;
    }

    /**
     * [funnel id, upsell step id] of the step that lists an offer; zeros
     * when no upsell step of a funnel lists it.
     *
     * @return array{0:int,1:int}
     */
    public function location(int $offer_id): array
    {
        $step_id   = (int) get_post_meta($offer_id, '_funnel_id', true);
        $funnel_id = $step_id > 0 ? $this->upsell_funnel($step_id) : 0;
        return $funnel_id > 0 && in_array($offer_id, $this->offer_ids($step_id), true) ? [ $funnel_id, $step_id ] : [ 0, 0 ];
    }

    public function is_offer(int $offer_id): bool
    {
        return self::OFFER_POST_TYPE === get_post_type($offer_id);
    }

    /** @return int[] an upsell step's offer ids in order. */
    public function offer_ids(int $step_id): array
    {
        return array_map(static fn (array $entry): int => (int) $entry['id'], $this->entries_of($step_id));
    }

    // ---------------------------------------------------------------- reads

    /** @return array<string,mixed> */
    public function view(int $offer_id): array
    {
        $post               = get_post($offer_id);
        [ $funnel, $step ]  = $this->location($offer_id);
        $entry              = null;
        $position           = null;
        foreach ($this->entries_of($step) as $i => $listed) {
            if ((int) $listed['id'] === $offer_id) {
                $entry    = $listed;
                $position = $i + 1;
            }
        }
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : (string) get_post_meta($offer_id, '_offer_type', true);

        return [
            'offer_id'  => $offer_id,
            'step_id'   => $step ?: null,
            'funnel_id' => $funnel ?: null,
            'title'     => $post instanceof \WP_Post ? sanitize_text_field($post->post_title) : null,
            'type'      => '' === $type ? null : sanitize_key($type),
            'status'    => $post instanceof \WP_Post && 'publish' === $post->post_status ? 'active' : 'inactive',
            'position'  => $position,
            'products'  => self::lines($this->products($offer_id)),
        ];
    }

    /**
     * An offer's product entries keyed by their hash key: product id under
     * 'id' and the key's fields beside it.
     *
     * @return array<string,array<string,mixed>>
     */
    public function products(int $offer_id): array
    {
        $setting  = self::setting($offer_id);
        $products = self::vars($setting->products ?? null);
        $fields   = self::vars($setting->fields ?? null);
        $out      = [];
        foreach ($products as $key => $product_id) {
            if ((int) $product_id > 0) {
                $out[ (string) $key ] = [ 'id' => (int) $product_id ] + self::vars($fields[ $key ] ?? null);
            }
        }
        return $out;
    }

    /**
     * Each entry as a line with the price a buyer pays, as the upsell
     * module prices an offer (WFOCU_Offers::get_product_price() and
     * WFOCU_Common::apply_discount()): the regular price, or the current
     * price for the _on_sale types, times the quantity, less the percentage,
     * or less the fixed amount once per unit.
     *
     * @param array<string,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function lines(array $entries): array
    {
        $out = [];
        foreach ($entries as $key => $entry) {
            $product_id = (int) $entry['id'];
            $variation  = (int) ($entry['default_variation'] ?? 0);
            $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            $priced     = $variation > 0 && function_exists('wc_get_product') ? wc_get_product($variation) : $product;
            $quantity   = max(1, (int) ($entry['quantity'] ?? 1));
            $type       = (string) ($entry['discount_type'] ?? 'percentage_on_reg');
            $amount     = (float) ($entry['discount_amount'] ?? 0);
            $line       = [
                'key'             => str_starts_with((string) $key, 'new_') ? null : (string) $key,
                'product_id'      => $product_id,
                'title'           => $product instanceof \WC_Product ? sanitize_text_field($product->get_name()) : null,
                'quantity'        => $quantity,
                'discount_type'   => $type,
                'discount_amount' => $amount,
                'unit_price'      => null,
                'line_total'      => null,
            ];
            if (! $priced instanceof \WC_Product || $priced->is_type('variable')) {
                $line['unit_price_unavailable'] = $priced instanceof \WC_Product ? 'a variable product is priced by the variation the buyer picks' : 'the product no longer exists';
                $out[] = $line;
                continue;
            }
            $base  = (str_ends_with($type, '_on_sale') ? (float) $priced->get_price('edit') : (float) $priced->get_regular_price('edit')) * $quantity;
            $total = str_starts_with($type, 'fixed_') ? $base - $amount * $quantity : $base - $base * ($amount / 100);
            $total = round(max(0.0, $total), function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2);

            $line['unit_price'] = $total / $quantity;
            $line['line_total'] = $total;
            $out[] = $line;
        }
        return $out;
    }

    // ---------------------------------------------------------------- writes

    /** Add an offer to the end of an upsell step's list; answers its id, or 0. */
    public function create(int $step_id, string $title, string $type): int
    {
        $before = $this->offer_ids($step_id);
        $data   = ($this->rest)('POST', sprintf('/funnelkit-app/funnel-upsell/%d/offers', $step_id), [], [ 'title' => $title, 'step_type' => $type ]);
        $added  = array_values(array_diff($this->offer_ids($step_id), $before));
        if ([] === $added) {
            FunnelKit_Writes::refuse('funnelkit_refused', FunnelKit_Writes::message($data, 'FunnelKit did not add the offer.'));
        }
        FunnelKit_Offer_Lists::refresh($step_id);
        return $added[0];
    }

    /**
     * Rename an offer or switch it on or off, through the save-state route
     * FunnelKit's offer screen uses.
     *
     * @param array{title?:string,status?:bool} $changes
     */
    public function save_state(int $offer_id, int $step_id, array $changes): void
    {
        // The route updates the list the upsell module cached for this
        // request; it must start from the stored one.
        FunnelKit_Offer_Lists::refresh($step_id);
        ($this->rest)('POST', sprintf('/funnelkit-app/funnel-offer/save-state/%d', $offer_id), [], $changes);
        clean_post_cache($offer_id);
        FunnelKit_Offer_Lists::refresh($step_id);
    }

    public function delete(int $offer_id, int $step_id): void
    {
        FunnelKit_Offer_Lists::refresh($step_id);
        $data = ($this->rest)('DELETE', sprintf('/funnelkit-app/funnel-upsell/%d/offers', $offer_id), [], [ 'upsell_id' => $step_id ]);
        FunnelKit_Offer_Lists::refresh($step_id);
        if (null !== get_post($offer_id) || in_array($offer_id, $this->offer_ids($step_id), true)) {
            FunnelKit_Writes::refuse('funnelkit_refused', FunnelKit_Writes::message($data, 'FunnelKit did not delete the offer.'));
        }
    }

    /**
     * Replace an offer's products with these lines, the way the offer
     * product routes store them. A product already on the offer keeps its
     * key and the fields a line leaves out; a new one gets the module's
     * defaults (quantity 1, no discount, percentage_on_reg, and for a
     * variable product its first variation). Answers the product ids taken
     * off.
     *
     * @param FunnelKit_Offer_Line[] $lines
     * @return int[]
     */
    public function save_products(int $offer_id, int $step_id, array $lines): array
    {
        $setting    = self::setting($offer_id);
        $products   = self::vars($setting->products ?? null);
        $fields     = self::vars($setting->fields ?? null);
        $variations = self::vars($setting->variations ?? null);
        $keys       = [];
        foreach ($products as $key => $product_id) {
            $keys[ (int) $product_id ] = (string) $key;
        }

        $new_products   = new \stdClass();
        $new_fields     = new \stdClass();
        $new_variations = new \stdClass();
        foreach ($lines as $line) {
            $key = $keys[ $line->product_id ] ?? null;
            if (null !== $key) {
                $field = (object) self::vars($fields[ $key ] ?? null);
                if (isset($variations[ $key ])) {
                    $new_variations->{$key} = $variations[ $key ];
                }
            } else {
                $key   = (string) call_user_func([ 'WFOCU_Common', 'get_product_id_hash' ], $step_id, $offer_id, $line->product_id);
                $field = (object) [ 'discount_amount' => 0, 'discount_type' => 'percentage_on_reg', 'quantity' => 1, 'shipping_cost_flat' => 0 ];
                $first = $this->first_variation($line->product_id);
                if ($first > 0) {
                    $field->default_variation = $first;
                    $field->variations_enable = true;
                    $new_variations->{$key}   = [ $first => (object) [ 'discount_amount' => 0, 'disount_on' => 'regular', 'vid' => $first ] ];
                }
            }
            if (null !== $line->quantity) {
                $field->quantity = $line->quantity;
            }
            if (null !== $line->discount_type) {
                $field->discount_type = $line->discount_type;
            }
            if (null !== $line->discount_amount) {
                $field->discount_amount = $line->discount_amount;
            }
            $new_products->{$key} = $line->product_id;
            $new_fields->{$key}   = $field;
        }

        $setting->products              = $new_products;
        $setting->fields                = $new_fields;
        $setting->variations            = $new_variations;
        $setting->have_multiple_product = count($lines) > 1 ? 2 : 1;

        call_user_func([ 'WFOCU_Common', 'update_offer' ], $offer_id, $setting, $step_id);
        call_user_func([ 'WFOCU_Common', 'update_funnel_time' ], $step_id);
        do_action('wfocu_offer_updated', $setting, $offer_id, $step_id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the upsell module's own action, fired as its product routes fire it.
        if (class_exists('WooFunnels_Transient') && method_exists('WooFunnels_Transient', 'get_instance')) {
            \WooFunnels_Transient::get_instance()->delete_all_transients('upstroke');
        }
        clean_post_cache($offer_id);

        $kept = array_map(static fn (FunnelKit_Offer_Line $line): int => $line->product_id, $lines);
        return array_values(array_diff(array_map('intval', array_keys($keys)), $kept));
    }

    /** Whether the upsell module lets an offer hold more than one product (its MultiProduct add-on). */
    public function allows_many_products(): bool
    {
        return is_callable([ 'WFOCU_Common', 'is_add_on_exist' ]) && (bool) call_user_func([ 'WFOCU_Common', 'is_add_on_exist' ], 'MultiProduct');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int,array<string,mixed>> an upsell step's list entries that name an offer, in order. */
    private function entries_of(int $step_id): array
    {
        $list = $step_id > 0 ? get_post_meta($step_id, FunnelKit_Offer_Lists::LIST_KEY, true) : null;
        $out  = [];
        foreach (is_array($list) ? $list : [] as $entry) {
            $entry = self::vars($entry);
            if ((int) ($entry['id'] ?? 0) > 0) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    private function first_variation(int $product_id): int
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (! $product instanceof \WC_Product || ! $product->is_type('variable')) {
            return 0;
        }
        $children = $product->get_children();
        return (int) ($children[0] ?? 0);
    }

    /** The offer's _wfocu_setting through the upsell module, as an object. */
    private static function setting(int $offer_id): \stdClass
    {
        $setting = is_callable([ 'WFOCU_Common', 'get_offer' ]) ? call_user_func([ 'WFOCU_Common', 'get_offer' ], $offer_id) : get_post_meta($offer_id, '_wfocu_setting', true);
        if (is_array($setting)) {
            $setting = (object) $setting;
        }
        return $setting instanceof \stdClass ? $setting : new \stdClass();
    }

    /**
     * @param mixed $value
     * @return array<int|string,mixed>
     */
    private static function vars($value): array
    {
        if (is_object($value)) {
            return get_object_vars($value);
        }
        return is_array($value) ? $value : [];
    }
}
