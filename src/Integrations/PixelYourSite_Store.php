<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * PixelYourSite's own settings storage (issue #1094), as PYS\Settings reads
 * and writes it in 11.x: one serialized row of {prefix}pys_options per
 * module (option_name pys_facebook, pys_ga, pys_gtm), or, on a site that
 * has not migrated to that table, the option of the same name.
 *
 * Only this class reads or writes those rows.
 */
final class PixelYourSite_Store
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- PixelYourSite's own settings table, which has no cached read API.

    private const TABLE = 'pys_options';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . self::TABLE;
    }

    public function has_table(): bool
    {
        return Ops_Status_Packs::table_exists($this->table());
    }

    public function has_row(string $name): bool
    {
        return null !== $this->raw($name);
    }

    /** @return array<string, mixed> */
    public function settings(string $name): array
    {
        if ($this->has_table()) {
            $raw = $this->raw($name);
            return self::string_keyed(null === $raw ? [] : maybe_unserialize($raw));
        }
        return self::string_keyed(get_option($name, []));
    }

    /** @return array<string, mixed> */
    private static function string_keyed(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[ (string) $key ] = $item;
        }
        return $out;
    }

    /** @param array<string, mixed> $settings */
    public function save(string $name, array $settings): void
    {
        if (! $this->has_table()) {
            update_option($name, $settings);
            return;
        }
        $table = $this->table();
        $done  = $this->db->update($table, [ 'option_value' => maybe_serialize($settings) ], [ 'option_name' => $name ]);
        if (false === $done) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused('update_failed', sprintf('Could not save PixelYourSite settings %s: %s', $name, $this->db->last_error));
        }
        Database_Guard::invalidate_caches($table, [ 'where' => [ 'option_name' => $name ] ]);
    }

    private function raw(string $name): ?string
    {
        $raw = $this->db->get_var($this->db->prepare('SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $this->table(), $name));
        return is_string($raw) ? $raw : null;
    }
}
