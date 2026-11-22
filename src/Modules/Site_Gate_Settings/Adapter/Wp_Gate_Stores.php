<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Integrations\Support\Plugin_Refusals;
use WPMCP\Integrations\Support\Site_Gates;
use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The gate stores, by plugin slug, and which one a call means (issue
 * #1099): the plugin it names, or with auto the one gate plugin that is
 * active, the way the toggles pick it.
 */
#[Provides(Gate_Stores::class)]
final class Wp_Gate_Stores implements Gate_Stores
{
    /** @var array<string, Gate_Store> slug => store, in Gate_Plugin order */
    private readonly array $stores;

    public function __construct(
        Seedprod_Store $seedprod,
        Ucp_Store $ucp,
        Wpmm_Store $wpmm,
        Maintenance_Store $maintenance,
        Cmp_Store $cmp,
        private readonly Site_Gates $gates,
        private readonly Plugin_Refusals $refusals,
    ) {
        $stores = [];
        foreach ([$seedprod, $ucp, $wpmm, $maintenance, $cmp] as $store) {
            $stores[ $store->slug() ] = $store;
        }
        $this->stores = $stores;
    }

    public function resolve(string $named): Gate_Store|Refusal
    {
        if ('auto' !== $named) {
            return $this->named($named);
        }

        $active = array_values(array_filter($this->stores, fn (Gate_Store $store): bool => $this->gates->plugin_active($store->slug())));
        if ([] === $active) {
            $labels = array_map(static fn (Gate_Store $store): string => $store->label(), array_values($this->stores));
            $refusal = $this->refusals->any_of('site_gate_plugin_inactive', 'None of ' . implode(', ', $labels) . ' is active on this site.');
            return new Refusal($refusal['code'], $refusal['message'], $refusal['data']);
        }
        if (count($active) > 1) {
            return new Refusal('plugin_required', 'More than one gate plugin is active; name one with plugin.', ['plugins' => array_map(static fn (Gate_Store $store): string => $store->slug(), $active)]);
        }
        return $active[0];
    }

    private function named(string $named): Gate_Store|Refusal
    {
        $store = $this->stores[ $named ] ?? null;
        if (null !== $store && $this->gates->plugin_active($named)) {
            return $store;
        }
        $refusal = $this->refusals->make('site_gate_plugin_inactive', null === $store ? $named : $store->label());
        return new Refusal($refusal['code'], $refusal['message'], $refusal['data']);
    }

    public function gate_active(Gate_Store $store): bool
    {
        return $this->gates->gate_active($store->slug());
    }
}
