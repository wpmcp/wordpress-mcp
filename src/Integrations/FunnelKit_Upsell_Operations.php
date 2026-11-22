<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\FunnelKit_Funnel_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit upsell offer writes (issue #1021, the rest of #500), as ops on
 * the theme dispatcher pair beside the funnel ops, so they add no
 * top-level tools:
 *
 *  - create-funnelkit-upsell-offer: an upsell or downsell offer, with no
 *    products yet, at the end of an upsell step's offers;
 *  - update-funnelkit-upsell-offer: rename it or switch it on or off;
 *  - set-funnelkit-upsell-offer-products: replace its products, with
 *    quantity and discount;
 *  - delete-funnelkit-upsell-offer (confirm).
 *
 * The offers themselves are read by get-funnelkit-funnel. They need
 * Funnel Builder Pro's one-click upsell module (WFOCU_Common, filterable
 * through wpmcp_funnelkit_offers_active) and answer funnelkit_pro_inactive
 * without it. Storage and how each write reaches Funnel Builder Pro are in
 * FunnelKit_Upsell_Store.
 *
 * Each write is snapshotted first as the whole funnel
 * (FunnelKit_Funnel_Snapshot, which counts the offer posts an upsell step
 * lists among the funnel's posts), so an offer a write created is deleted
 * on undo and one it deleted comes back at its own id and place. Creates,
 * updates and product sets take dry_run.
 */
final class FunnelKit_Upsell_Operations
{
    /** Most products one set-funnelkit-upsell-offer-products call may name. */
    private const MAX_PRODUCTS = 20;

    private const OFFER_TYPES = [ 'upsell', 'downsell' ];

    public function __construct(private readonly FunnelKit_Upsell_Store $store)
    {
    }

    /** Whether the upsell module is loaded, filterable through wpmcp_funnelkit_offers_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_offers_active', class_exists('WFOCU_Common'));
    }

    /**
     * @param callable $requires FunnelKit's own presence check.
     * @return array<string,array<string,mixed>>
     */
    public static function operations(callable $requires): array
    {
        return (new self(new FunnelKit_Upsell_Store()))->definitions($requires);
    }

    /**
     * @param callable $requires
     * @return array<string,array<string,mixed>>
     */
    public function definitions(callable $requires): array
    {
        $gate = static function () use ($requires) {
            $out = $requires();
            return true === $out ? Ops_Status_Packs::presence(self::is_active(), 'funnelkit_pro_inactive', 'FunnelKit Funnel Builder Pro') : $out;
        };
        $id        = [ 'type' => 'integer', 'minimum' => 1 ];
        $title     = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ];
        $dry_run   = [ 'type' => 'boolean' ];
        $base      = [ 'tier' => 'pro', 'capability' => 'manage_woocommerce', 'requires' => $gate ];
        $by_offer  = fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => $this->store->location((int) $args['offer_id'])[0] ];
        $by_step   = fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => $this->store->upsell_funnel((int) $args['step_id']) ];
        $offer_arg = [ 'type' => 'object', 'properties' => [ 'offer_id' => $id ], 'required' => [ 'offer_id' ] ];

        return [
            'create-funnelkit-upsell-offer'       => $base + [
                'mode'         => 'write',
                'description'  => 'Add an upsell (default) or downsell offer, with no products yet, to the end of a FunnelKit upsell step. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'step_id' => $id, 'title' => $title, 'type' => [ 'type' => 'string', 'enum' => self::OFFER_TYPES ], 'dry_run' => $dry_run ],
                    'required'   => [ 'step_id', 'title' ],
                ],
                'validate'     => fn (array $args): ?array => $this->step_refusal((int) $args['step_id']),
                'snapshot'     => $by_step,
                'handler'      => fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, fn (): array => $this->create($args)),
            ],
            'update-funnelkit-upsell-offer'       => $base + [
                'mode'         => 'write',
                'description'  => 'Rename a FunnelKit upsell offer or switch it on (enabled true) or off; fields not passed are kept. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'offer_id' => $id, 'title' => $title, 'enabled' => [ 'type' => 'boolean' ], 'dry_run' => $dry_run ],
                    'required'   => [ 'offer_id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->offer_refusal((int) $args['offer_id']) ?? (isset($args['title']) || isset($args['enabled']) ? null : [
                    'code'    => 'nothing_to_update',
                    'message' => 'Pass title, enabled or both.',
                    'data'    => [ 'offer_id' => (int) $args['offer_id'] ],
                ]),
                'snapshot'     => $by_offer,
                'handler'      => fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, fn (): array => $this->update($args)),
            ],
            'set-funnelkit-upsell-offer-products' => $base + [
                'mode'         => 'write',
                'description'  => 'Replace a FunnelKit upsell offer\'s products, each with quantity and discount; products not listed come off, ones kept keep their settings. Answers each line\'s price; dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'offer_id' => $id,
                        'products' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PRODUCTS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'      => $id,
                                    'quantity'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
                                    'discount_type'   => [ 'type' => 'string', 'enum' => FunnelKit_Offer_Line::DISCOUNT_TYPES ],
                                    'discount_amount' => [ 'type' => 'number', 'minimum' => 0 ],
                                ],
                                'required'   => [ 'product_id' ],
                            ],
                        ],
                        'dry_run'  => $dry_run,
                    ],
                    'required'   => [ 'offer_id', 'products' ],
                ],
                'validate'     => fn (array $args): ?array => $this->offer_refusal((int) $args['offer_id']) ?? $this->products_refusal((array) $args['products']),
                'snapshot'     => $by_offer,
                'handler'      => fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, fn (): array => $this->set_products($args)),
            ],
            'delete-funnelkit-upsell-offer'       => $base + [
                'mode'         => 'destructive',
                'description'  => 'Delete a FunnelKit upsell offer from its upsell step. Snapshotted; rollback brings it back at its own id and place',
                'input_schema' => $offer_arg,
                'validate'     => fn (array $args): ?array => $this->offer_refusal((int) $args['offer_id']),
                'snapshot'     => $by_offer,
                'handler'      => fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, fn (): array => $this->delete((int) $args['offer_id'])),
            ],
        ];
    }

    // ---------------------------------------------------------------- refusals

    /** @return array{code:string,message:string,data:array<string,mixed>}|null */
    private function step_refusal(int $step_id): ?array
    {
        if ('wfocu_funnel' !== get_post_type($step_id)) {
            return [ 'code' => 'upsell_step_not_found', 'message' => 'No FunnelKit upsell step has that id.', 'data' => [ 'step_id' => $step_id ] ];
        }
        if (0 === $this->store->upsell_funnel($step_id)) {
            return [ 'code' => 'upsell_step_not_in_funnel', 'message' => 'This upsell step is not a step of a FunnelKit funnel, so it cannot be snapshotted with one.', 'data' => [ 'step_id' => $step_id ] ];
        }
        return null;
    }

    /** @return array{code:string,message:string,data:array<string,mixed>}|null */
    private function offer_refusal(int $offer_id): ?array
    {
        if (! $this->store->is_offer($offer_id)) {
            return [ 'code' => 'offer_not_found', 'message' => 'No FunnelKit upsell offer has that id.', 'data' => [ 'offer_id' => $offer_id ] ];
        }
        if (0 === $this->store->location($offer_id)[0]) {
            return [ 'code' => 'offer_not_in_funnel', 'message' => 'This offer is not listed by an upsell step of a FunnelKit funnel, so it cannot be snapshotted with one.', 'data' => [ 'offer_id' => $offer_id ] ];
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private function products_refusal(array $products): ?array
    {
        $refusal = FunnelKit_Writes::products_refusal($products);
        if (null !== $refusal) {
            return $refusal;
        }
        foreach (FunnelKit_Offer_Line::all($products) as $line) {
            if (null !== $line->discount_type && str_starts_with($line->discount_type, 'percentage_') && (float) $line->discount_amount > 100) {
                return [ 'code' => 'invalid_discount', 'message' => 'A percentage discount cannot exceed 100.', 'data' => [ 'product_id' => $line->product_id ] ];
            }
        }
        if (count($products) > 1 && ! $this->store->allows_many_products()) {
            return [ 'code' => 'multiple_products_need_addon', 'message' => 'An offer holds one product unless Funnel Builder Pro\'s multiple products add-on is active.', 'data' => [ 'count' => count($products) ] ];
        }
        return null;
    }

    // ---------------------------------------------------------------- writes

    /** @return array<string,mixed> */
    private function create(array $args): array
    {
        $step_id = (int) $args['step_id'];
        $title   = sanitize_text_field((string) $args['title']);
        $type    = in_array($args['type'] ?? null, self::OFFER_TYPES, true) ? (string) $args['type'] : 'upsell';
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'would_create' => [ 'step_id' => $step_id, 'title' => $title, 'type' => $type, 'position' => count($this->store->offer_ids($step_id)) + 1 ] ];
        }
        return [ 'offer' => $this->store->view($this->store->create($step_id, $title, $type)) ];
    }

    /** @return array<string,mixed> */
    private function update(array $args): array
    {
        $offer_id = (int) $args['offer_id'];
        $current  = $this->store->view($offer_id);
        $body     = [];
        $changes  = [];
        if (isset($args['title'])) {
            $body['title']    = sanitize_text_field((string) $args['title']);
            $changes['title'] = [ $current['title'], $body['title'] ];
        }
        if (isset($args['enabled'])) {
            $body['status']    = (bool) $args['enabled'];
            $changes['status'] = [ $current['status'], $body['status'] ? 'active' : 'inactive' ];
        }
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'offer_id' => $offer_id, 'changes' => $changes ];
        }
        $this->store->save_state($offer_id, (int) $current['step_id'], $body);
        return [ 'offer' => $this->store->view($offer_id) ];
    }

    /** @return array<string,mixed> */
    private function set_products(array $args): array
    {
        $offer_id = (int) $args['offer_id'];
        $lines    = FunnelKit_Offer_Line::all((array) $args['products']);
        if (! empty($args['dry_run'])) {
            $stored  = $this->store->products($offer_id);
            $by      = array_column(array_map(static fn (string $key, array $entry): array => [ 'key' => $key, 'id' => (int) $entry['id'] ], array_keys($stored), $stored), 'key', 'id');
            $preview = [];
            foreach ($lines as $line) {
                $key             = $by[ $line->product_id ] ?? 'new_' . $line->product_id;
                $entry           = $stored[ $key ] ?? [ 'id' => $line->product_id, 'quantity' => 1, 'discount_type' => 'percentage_on_reg', 'discount_amount' => 0 ];
                $preview[ $key ] = array_merge($entry, array_filter([ 'quantity' => $line->quantity, 'discount_type' => $line->discount_type, 'discount_amount' => $line->discount_amount ], static fn ($value): bool => null !== $value));
            }
            $kept = array_map(static fn (FunnelKit_Offer_Line $line): int => $line->product_id, $lines);
            return [ 'dry_run' => true, 'removed' => array_values(array_diff(array_map('intval', array_keys($by)), $kept)), 'products' => FunnelKit_Upsell_Store::lines($preview) ];
        }

        // The upsell module's methods are called directly here (see
        // FunnelKit_Upsell_Store), so the access check its routes make is
        // asked first.
        if (! FunnelKit_Funnel_Snapshot::current_user_may_edit_funnels()) {
            FunnelKit_Writes::refuse('funnelkit_access_denied', 'FunnelKit refused this change: its own funnel access check does not let the current user edit funnels.');
        }
        [ , $step_id ] = $this->store->location($offer_id);
        $removed       = $this->store->save_products($offer_id, $step_id, $lines);
        return [ 'removed' => $removed, 'products' => FunnelKit_Upsell_Store::lines($this->store->products($offer_id)) ];
    }

    /** @return array<string,mixed> */
    private function delete(int $offer_id): array
    {
        [ , $step_id ] = $this->store->location($offer_id);
        $this->store->delete($offer_id, $step_id);
        return [ 'deleted' => true, 'offer_id' => $offer_id, 'step_id' => $step_id, 'offer_ids' => $this->store->offer_ids($step_id) ];
    }
}
