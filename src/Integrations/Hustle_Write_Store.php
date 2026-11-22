<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hustle 7's module storage for the writes of issue #1091: the
 * hustle_modules row (active switch) and the JSON rows of
 * hustle_modules_meta (content, settings, visibility, schedule_flags),
 * written as Hustle_Model::update_meta() writes them, and Hustle's object
 * cache of both (groups hustle_model_data and hustle_module_meta, keyed by
 * module id), dropped as Hustle_Model::clean_module_cache() drops it.
 *
 * Only this class touches those tables and cache groups.
 */
final class Hustle_Write_Store
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hustle's own tables; its model writes them the same way and keeps its own cache, dropped here.

    public const MODULES = 'hustle_modules';

    public const META = 'hustle_modules_meta';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    public function module_exists(int $id): bool
    {
        return null !== $this->db->get_var($this->db->prepare('SELECT module_id FROM %i WHERE module_id = %d', $this->table(self::MODULES), $id));
    }

    public function has_meta(int $id, string $key): bool
    {
        return null !== $this->db->get_var($this->db->prepare('SELECT meta_id FROM %i WHERE module_id = %d AND meta_key = %s LIMIT 1', $this->table(self::META), $id, $key));
    }

    /** @return array<string, mixed> One meta row decoded as Hustle_Model::get_settings_meta() decodes it. */
    public function meta(int $id, string $key): array
    {
        $raw = $this->db->get_var($this->db->prepare('SELECT meta_value FROM %i WHERE module_id = %d AND meta_key = %s LIMIT 1', $this->table(self::META), $id, $key));
        if (! is_string($raw)) {
            return [];
        }
        $value = json_decode($raw, true);
        if (! is_array($value)) {
            $value = json_decode(stripslashes($raw), true);
        }
        return Typed::map($value);
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return (string) wp_json_encode($value);
    }

    /** @param array<string, mixed> $value */
    public function save_meta(int $id, string $key, array $value): void
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- columns of Hustle's own meta table, keyed by module_id.
        $done = $this->db->update($this->table(self::META), [ 'meta_value' => self::encode($value) ], [ 'module_id' => $id, 'meta_key' => $key ]);
        if (false === $done) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not save the %s of Hustle module %d.', $key, $id));
        }
        $this->clear_cache($id);
    }

    /**
     * Empty the derived schedule flags, as Hustle does when its settings
     * change, so the next request checks the schedule again. A module that
     * never stored flags already checks it.
     */
    public function reset_schedule_flags(int $id): void
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- columns of Hustle's own meta table, keyed by module_id.
        $this->db->update($this->table(self::META), [ 'meta_value' => '[]' ], [ 'module_id' => $id, 'meta_key' => 'schedule_flags' ]);
        $this->clear_cache($id);
    }

    public function set_active(int $id, bool $active): void
    {
        if (false === $this->db->update($this->table(self::MODULES), [ 'active' => $active ? 1 : 0 ], [ 'module_id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not switch Hustle module %d.', $id));
        }
        $this->clear_cache($id);
    }

    public function active(int $id): bool
    {
        return 1 === Typed::int($this->db->get_var($this->db->prepare('SELECT active FROM %i WHERE module_id = %d', $this->table(self::MODULES), $id)));
    }

    public function clear_cache(int $id): void
    {
        wp_cache_delete($id, 'hustle_model_data');
        wp_cache_delete($id, 'hustle_module_meta');
    }

    /**
     * After an undo put rows of Hustle's tables back: drop every module's
     * cached copy and reset every module's schedule flags, since the rows
     * restored do not say which module they were.
     */
    public function refresh_all(): void
    {
        foreach (Typed::ints($this->db->get_col($this->db->prepare('SELECT module_id FROM %i', $this->table(self::MODULES)))) as $id) {
            $this->reset_schedule_flags($id);
        }
    }
}
