<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * pixelyoursite-set-settings (issue #1094), next to the PixelYourSite reads
 * in Tracking_Pack (#899): for one network (facebook, ga or gtm) set the IDs,
 * switch the module on or off, and switch named <event>_enabled events the
 * module already stores. The conversion API token is never read or written
 * here. One module row per call, under a db_rows before-image of that
 * pys_options row (or an option snapshot on a site without the table), so
 * rollback-operation puts it back exactly. Off until
 * wpmcp_enable_tracking_write, like the other tracking writes.
 */
final class PixelYourSite_Operations
{
    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly PixelYourSite_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'pixelyoursite-set-settings' => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'requires'           => $this->presence,
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_tracking_write', false),
                'opt_in_filter'      => Tracking_Pack::ENABLE_FILTER,
                'description'        => 'Set PixelYourSite IDs for one network (facebook pixel, ga measurement or ads IDs, gtm containers), turn the network on or off (enabled) and switch named *_enabled events it stores (tracking-get-settings lists them). The conversion API token is left alone. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_tracking_write filter)',
                'input_schema'       => [
                    'type'                 => 'object',
                    'properties'           => [
                        'network' => [ 'type' => 'string', 'enum' => PixelYourSite_Network::names() ],
                        'ids'     => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [ 'type' => 'string', 'maxLength' => 40 ] ],
                        'enabled' => [ 'type' => 'boolean' ],
                        'events'  => [ 'type' => 'object', 'maxProperties' => 100, 'additionalProperties' => [ 'type' => 'boolean' ] ],
                    ],
                    'required'             => [ 'network' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'           => fn (array $args): ?array => $this->refusal(PixelYourSite_Change::from_args($args)),
                'snapshot'           => fn (array $args): ?array => $this->snapshot(PixelYourSite_Change::from_args($args)),
                'handler'            => fn (array $args): array => $this->write(PixelYourSite_Change::from_args($args)),
            ],
        ];
    }

    /** @return array{code: string, message: string, data: array<string, string>} */
    private static function invalid(string $message, string $setting): array
    {
        return [ 'code' => 'invalid_setting', 'message' => $message, 'data' => [ 'setting' => $setting ] ];
    }

    /** @return array{code: string, message: string, data: array<string, string>}|null */
    private function refusal(PixelYourSite_Change $change): ?array
    {
        $network = $change->network;
        if ($this->store->has_table() && ! $this->store->has_row($network->row())) {
            return [ 'code' => 'pixelyoursite_settings_missing', 'message' => sprintf('PixelYourSite has not saved its %s settings yet; save that tab once in PixelYourSite, then try again.', $network->value), 'data' => [ 'network' => $network->value ] ];
        }
        foreach ($change->ids ?? [] as $id) {
            if (! $network->accepts($id)) {
                return self::invalid(sprintf('"%s" is not a valid %s ID for PixelYourSite.', $id, $network->value), $network->id_key());
            }
        }
        $stored = $this->store->settings($network->row());
        foreach (array_keys($change->events) as $event) {
            if (! str_ends_with($event, '_enabled') || 'enabled' === $event || ! array_key_exists($event, $stored)) {
                return self::invalid(sprintf('"%s" is not an event switch PixelYourSite stores for %s; tracking-get-settings lists them.', $event, $network->value), $event);
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function snapshot(PixelYourSite_Change $change): ?array
    {
        $row = $change->network->row();
        if (! $this->store->has_table()) {
            return [ 'object_type' => 'option', 'object_id' => $row ];
        }
        $table = Database_Guard::valid_table($this->store->table());
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'option_name' => $row ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        $after = maybe_serialize($change->apply($this->store->settings($row)));
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [ 'table' => $table, 'operation' => 'update', 'primary_key' => [ 'id' ], 'where' => $where, 'set' => [ 'option_value' => $after ], 'rows' => $before ],
        ];
    }

    /** @return array{network: string, changed: list<string>, ids: list<mixed>, enabled: bool} */
    private function write(PixelYourSite_Change $change): array
    {
        $row      = $change->network->row();
        $settings = $change->apply($this->store->settings($row));
        $this->store->save($row, $settings);
        $ids = $settings[ $change->network->id_key() ] ?? [];

        return [ 'network' => $change->network->value, 'changed' => $change->changed(), 'ids' => is_array($ids) ? array_values($ids) : [], 'enabled' => true === ($settings['enabled'] ?? false) ];
    }
}
