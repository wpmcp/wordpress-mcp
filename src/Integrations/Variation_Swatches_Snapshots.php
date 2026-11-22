<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo for the Variation Swatches type write (issue #1096): a db_rows
 * before-image of the attribute's woocommerce_attribute_taxonomies row, so
 * rollback-operation puts the row back exactly. WooCommerce caches those
 * rows in a transient and an object cache group; Variation_Swatches_Pack
 * drops both after the restore (wpmcp_rollback_db_rows_restored).
 */
final class Variation_Swatches_Snapshots
{
    public function __construct(private readonly Variation_Swatches_Store $store)
    {
    }

    /** @return array<string, mixed>|null */
    public function type(int $id, string $type): ?array
    {
        $table = Database_Guard::valid_table($this->store->table());
        if (is_wp_error($table)) {
            return null;
        }
        $where  = [ 'attribute_id' => $id ];
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
                'primary_key' => [ 'attribute_id' ],
                'where'       => $where,
                'set'         => [ 'attribute_type' => $type ],
                'rows'        => $before,
            ],
        ];
    }
}
