<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo for the Hustle writes (#1091): a db_rows before-image of the one
 * hustle_modules_meta row a write changes, or of the module row for the
 * active switch, so rollback-operation puts it back exactly; Hustle's cache
 * and schedule flags are refreshed after the restore (Hustle_Pack).
 */
final class Hustle_Write_Snapshots
{
    public function __construct(private readonly Hustle_Write_Store $store)
    {
    }

    /**
     * @param array<string, mixed> $after The row's value after the write.
     * @return array<string, mixed>|null
     */
    public function meta(int $id, string $key, array $after): ?array
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- columns of Hustle's own meta table, keyed by module_id.
        return $this->rows(Hustle_Write_Store::META, [ 'module_id' => $id, 'meta_key' => $key ], [ 'meta_id' ], [ 'meta_value' => Hustle_Write_Store::encode($after) ]);
    }

    /** @return array<string, mixed>|null */
    public function module(int $id, bool $active): ?array
    {
        return $this->rows(Hustle_Write_Store::MODULES, [ 'module_id' => $id ], [ 'module_id' ], [ 'active' => $active ? 1 : 0 ]);
    }

    /**
     * @param array<string, int|string> $where
     * @param list<string>              $primary
     * @param array<string, int|string> $set
     * @return array<string, mixed>|null
     */
    private function rows(string $name, array $where, array $primary, array $set): ?array
    {
        $table = Database_Guard::valid_table($this->store->table($name));
        if (is_wp_error($table)) {
            return null;
        }
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [ 'table' => $table, 'operation' => 'update', 'primary_key' => $primary, 'where' => $where, 'set' => $set, 'rows' => $before ],
        ];
    }
}
