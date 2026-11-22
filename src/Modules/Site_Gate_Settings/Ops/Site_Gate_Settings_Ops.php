<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Ops;

use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Contract\Operation_Provider;
use WPMCP\Integrations\Support\Handler_Refusal;
use WPMCP\Modules\Site_Gate_Settings\Adapter\Gate_Store;
use WPMCP\Modules\Site_Gate_Settings\Adapter\Gate_Stores;
use WPMCP\Modules\Site_Gate_Settings\Adapter\Plan_Writer;
use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Content_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Gate_Plugin;
use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;
use WPMCP\Modules\Site_Gate_Settings\Value\Stored;
use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The site gate settings ops on the theme pair (issue #1099): their schemas,
 * the checks that run before any snapshot, and the masked views they return.
 *
 *  - get-site-gate-settings reads one gate plugin's page content and who
 *    bypasses it. IP addresses and bypass links are counts and booleans.
 *  - set-site-gate-content edits the headline, message, logo and countdown
 *    where the plugin stores them as settings.
 *  - set-site-gate-bypass edits allowed roles, users, IP allowlists and the
 *    bypass link. It needs confirm:true; the preview and the result carry
 *    counts only, and a new link is accepted but never returned.
 *
 * Both writes are off until the site opts in on the site gate toggles' own
 * switch, wpmcp_enable_site_gate_write, which Opt_In_Settings already
 * declares for them; this class reuses it rather than declaring a second
 * gate. A bypass change that would gate the acting user out while the gate
 * is on is refused as the toggles refuse it (admin_not_bypassed). A refusal
 * writes nothing and takes no snapshot.
 *
 * operations() is metadata only; each method key names an instance method,
 * run on the instance the container builds with the adapters injected.
 */
#[Operations(dispatcher: 'theme')]
final class Site_Gate_Settings_Ops implements Operation_Provider
{
    public const OPT_IN_FILTER = 'wpmcp_enable_site_gate_write';

    private const LINK_PATTERN = '^([A-Za-z0-9_-]{8,64})?$';

    public function __construct(
        private readonly Gate_Stores $stores,
        private readonly Plan_Writer $writer,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The toggles' own switch (OPT_IN_FILTER), spelled out for the hook name sniff.
        $enabled = (bool) apply_filters('wpmcp_enable_site_gate_write', false);
        $plugin = ['type' => 'string', 'enum' => array_merge(['auto'], Gate_Plugin::slugs())];
        $roles = ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64], 'maxItems' => 50];

        return [
            'get-site-gate-settings' => [
                'mode' => 'read',
                'capability' => 'manage_options',
                'description' => 'One gate plugin\'s page content (headline, message, logo, countdown) and who bypasses it (roles, users, IP and link as counts and booleans; addresses and links are never returned), with the fields set-site-gate-content and set-site-gate-bypass can edit there',
                'input_schema' => ['type' => 'object', 'properties' => ['plugin' => $plugin]],
                'validate' => 'read_refusal',
                'handler' => 'read',
            ],
            'set-site-gate-content' => [
                'mode' => 'write',
                'capability' => 'manage_options',
                'description' => 'Edit the gate page headline, message (HTML), logo (attachment id, 0 removes) or countdown (ISO date, null turns it off) where the plugin stores them as settings: Under Construction, WP Maintenance Mode classic page, Maintenance designer modules, CMP. SeedProd is refused (edit in its builder). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_site_gate_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter' => self::OPT_IN_FILTER,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => $plugin,
                        'headline' => ['type' => 'string', 'maxLength' => 500],
                        'message' => ['type' => 'string', 'maxLength' => 20000],
                        'logo' => ['type' => 'integer', 'minimum' => 0],
                        'countdown' => ['type' => ['string', 'null'], 'maxLength' => 40],
                    ],
                ],
                'validate' => 'content_refusal',
                'snapshot' => 'content_target',
                'handler' => 'write_content',
            ],
            'set-site-gate-bypass' => [
                'mode' => 'destructive',
                'capability' => 'manage_options',
                'description' => 'Edit who bypasses the gate: roles, backend_roles (WP Maintenance Mode), users (Under Construction), ip_allowlist (Maintenance, WP Maintenance Mode), bypass_link (Maintenance, CMP; "" turns it off) and logged_in_bypass (Maintenance). Refuses a change that would gate you out while the gate is on. Needs confirm:true; addresses and links are never returned. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_site_gate_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter' => self::OPT_IN_FILTER,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plugin' => $plugin,
                        'roles' => $roles,
                        'backend_roles' => $roles,
                        'users' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 100],
                        'ip_allowlist' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 45], 'maxItems' => 100],
                        'bypass_link' => ['type' => 'string', 'pattern' => self::LINK_PATTERN],
                        'logged_in_bypass' => ['type' => 'boolean'],
                    ],
                ],
                'validate' => 'bypass_refusal',
                'confirm_preview' => 'bypass_preview',
                'snapshot' => 'bypass_target',
                'handler' => 'write_bypass',
            ],
        ];
    }

    /** @param array<mixed> $args */
    private function store(array $args): Gate_Store|Refusal
    {
        return $this->stores->resolve(Stored::text($args['plugin'] ?? 'auto'));
    }

    // ---------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function read_refusal(array $args): ?array
    {
        $store = $this->store($args);
        return $store instanceof Refusal ? $store->to_array() : null;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function read(array $args): array
    {
        $store = self::resolved($this->store($args));
        return [
            'plugin' => $store->slug(),
            'label' => $store->label(),
            'gate_active' => $this->stores->gate_active($store),
            'content' => $store->content(),
            'bypass' => $store->bypass()->masked() + ['editable' => $store->bypass_fields()],
        ];
    }

    // ---------------------------------------------------------------
    // Content
    // ---------------------------------------------------------------

    /** @param array<mixed> $args */
    private function content_plan(array $args): Write_Plan|Refusal
    {
        $store = $this->store($args);
        if ($store instanceof Refusal) {
            return $store;
        }
        $change = Content_Change::from_args($args);
        if ($change instanceof Refusal) {
            return $change;
        }
        if ([] === $change->fields()) {
            return new Refusal('nothing_to_change', 'Name at least one of headline, message, logo or countdown.');
        }
        $plan = $store->plan_content($change);
        if ($plan instanceof Write_Plan && ! $this->writer->changes($plan)) {
            return new Refusal('no_change', sprintf('%s already shows that. Nothing was written and no undo point was taken.', $store->label()));
        }
        return $plan;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function content_refusal(array $args): ?array
    {
        $plan = $this->content_plan($args);
        return $plan instanceof Refusal ? $plan->to_array() : null;
    }

    /**
     * @param array<mixed> $args
     * @return array{object_type: string, object_id: int|string}|null
     */
    public function content_target(array $args): ?array
    {
        return $this->target($this->content_plan($args));
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function write_content(array $args): array
    {
        $plan = self::planned($this->content_plan($args));
        $store = self::resolved($this->store($args));
        $this->writer->apply($plan);

        return [
            'plugin' => $store->slug(),
            'content' => $store->content(),
            'cache_hint' => 'Purge page caches so visitors see the change.',
            'undo' => 'rollback-operation restores the plugin settings exactly.',
        ];
    }

    // ---------------------------------------------------------------
    // Bypass
    // ---------------------------------------------------------------

    /**
     * @param array<mixed> $args
     * @return array{0: ?Gate_Store, 1: Write_Plan|Refusal}
     */
    private function bypass_plan(array $args): array
    {
        $store = $this->store($args);
        if ($store instanceof Refusal) {
            return [null, $store];
        }
        $change = Bypass_Change::from_args($args);
        if ($change instanceof Refusal) {
            return [$store, $change];
        }
        if ([] === $change->fields()) {
            return [$store, new Refusal('nothing_to_change', 'Name at least one of roles, backend_roles, users, ip_allowlist, bypass_link or logged_in_bypass.')];
        }
        return [$store, $this->checked_bypass($store, $store->plan_bypass($change))];
    }

    /** The plan, unless it would gate the acting user out or change nothing. */
    private function checked_bypass(Gate_Store $store, Write_Plan|Refusal $plan): Write_Plan|Refusal
    {
        if ($plan instanceof Refusal) {
            return $plan;
        }
        if (null !== $plan->bypass_after && $this->stores->gate_active($store) && ! $store->lets_through($plan->bypass_after)) {
            return new Refusal('admin_not_bypassed', sprintf('%s is closing the site, and after this change it would gate you out too. Keep your role (or, for Maintenance, the logged-in bypass) in the change, or turn the gate off first.', $store->label()), ['roles' => array_values(wp_get_current_user()->roles)]);
        }
        if (! $this->writer->changes($plan)) {
            return new Refusal('no_change', sprintf('%s already lets through exactly that. Nothing was written and no undo point was taken.', $store->label()));
        }
        return $plan;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function bypass_refusal(array $args): ?array
    {
        $plan = $this->bypass_plan($args)[1];
        return $plan instanceof Refusal ? $plan->to_array() : null;
    }

    /**
     * @param array<mixed> $args
     * @return array{message: string, data: array<string, mixed>} counts and booleans only.
     */
    public function bypass_preview(array $args): array
    {
        [$store, $plan] = $this->bypass_plan($args);
        $after = $plan instanceof Write_Plan && null !== $plan->bypass_after ? $plan->bypass_after->masked() : [];
        $label = null === $store ? 'The gate plugin' : $store->label();

        return [
            'message' => sprintf('%s will let through: %s. IP addresses and bypass links are shown as counts only.', $label, self::describe($after)),
            'data' => ['plugin' => $store?->slug(), 'bypass_after' => $after],
        ];
    }

    /**
     * @param array<mixed> $args
     * @return array{object_type: string, object_id: int|string}|null
     */
    public function bypass_target(array $args): ?array
    {
        return $this->target($this->bypass_plan($args)[1]);
    }

    /** @param array<string, list<string>|int|bool> $masked */
    private static function describe(array $masked): string
    {
        $parts = [];
        foreach ($masked as $key => $value) {
            $parts[] = $key . ' ' . match (true) {
                is_array($value) => '[' . implode(', ', $value) . ']',
                is_bool($value) => $value ? 'yes' : 'no',
                default => (string) $value,
            };
        }
        return [] === $parts ? 'unchanged' : implode('; ', $parts);
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function write_bypass(array $args): array
    {
        [$store, $plan] = $this->bypass_plan($args);
        $plan = self::planned($plan);
        if (null === $store) {
            throw new Handler_Refusal('site_gate_plugin_inactive', 'No gate plugin.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
        }
        $this->writer->apply($plan);

        $out = [
            'plugin' => $store->slug(),
            'bypass' => $store->bypass()->masked(),
            'cache_hint' => 'Purge page caches so the new bypass rules apply to cached pages.',
            'undo' => 'rollback-operation restores the previous bypass settings exactly.',
        ];
        if ([] !== $plan->notes) {
            $out['note'] = implode(' ', $plan->notes);
        }
        return $out;
    }

    /** @return array{object_type: string, object_id: int|string}|null */
    private function target(Write_Plan|Refusal $plan): ?array
    {
        return $plan instanceof Write_Plan ? $this->writer->target($plan) : null;
    }

    /**
     * The store, or the refusal raised on the dispatcher's error channel.
     * validate() refuses first, so a handler meets this only if the site
     * changed between the two.
     */
    private static function resolved(Gate_Store|Refusal $store): Gate_Store
    {
        if ($store instanceof Refusal) {
            throw new Handler_Refusal($store->code, $store->message, $store->data); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
        }
        return $store;
    }

    /** The plan, or its refusal raised on the dispatcher's error channel, as resolved() does. */
    private static function planned(Write_Plan|Refusal $plan): Write_Plan
    {
        if ($plan instanceof Refusal) {
            throw new Handler_Refusal($plan->code, $plan->message, $plan->data); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
        }
        return $plan;
    }
}
