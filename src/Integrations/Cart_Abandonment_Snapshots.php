<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo for the Cart Abandonment Recovery writes (issue #1096): a db_rows
 * before-image of the follow-up email's template row for a template edit,
 * and an option_set of the two timing options for a timing change, each
 * restored with rollback-operation.
 */
final class Cart_Abandonment_Snapshots
{
    public const TIMING_OPTIONS = [ 'wcf_ca_cron_run_time', 'wcf_ca_cart_lost_time' ];

    public function __construct(private readonly Cart_Abandonment_Store $store)
    {
    }

    /**
     * @param array<string, int|string> $set The columns the edit writes.
     * @return array<string, mixed>|null
     */
    public function template(int $id, array $set): ?array
    {
        $table = Database_Guard::valid_table($this->store->table(Cart_Abandonment_Store::TEMPLATES));
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'id' => $id ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'update',
                'primary_key' => [ 'id' ],
                'where'       => $where,
                'set'         => $set,
                'rows'        => $before,
            ],
        ];
    }

    /** @return array{object_type: string, object_id: string} */
    public function timing(): array
    {
        return [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(self::TIMING_OPTIONS) ];
    }
}
