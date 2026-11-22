<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPML String Translation storage (issue #1053): reads and writes its two
 * string tables, nothing else.
 *  - {prefix}icl_strings: one row per registered string (id, language = the
 *    string's source language, context = its domain, name, value, status);
 *  - {prefix}icl_string_translations: one row per string and language
 *    (string_id, language, status, value, translator_id,
 *    translation_date). Status 10 (ICL_TM_COMPLETE) is a finished
 *    translation; 0 (ICL_TM_NOT_TRANSLATED) and 3 (ICL_TM_NEEDS_UPDATE) are
 *    not.
 * A string's own status in icl_strings sums its translations up: 10 when
 * every target language is complete, 2 (ICL_STRING_TRANSLATION_PARTIAL)
 * when some are, 0 when none is, the way WPML's icl_update_string_status()
 * computes it.
 */
final class WPML_String_Store
{
    public const STRINGS      = 'icl_strings';
    public const TRANSLATIONS = 'icl_string_translations';

    public const COMPLETE       = 10;
    public const PARTIAL        = 2;
    public const NOT_TRANSLATED = 0;

    public function __construct(private readonly \wpdb $db)
    {
    }

    private function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    public function ready(): bool
    {
        foreach ([ self::STRINGS, self::TRANSLATIONS ] as $name) {
            $table = $this->table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a presence check of a third-party table.
            if ($table !== $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every string outside a string package (packages are page builder
     * content, translated with their post), ordered by domain then id.
     *
     * @return array<int, array{id: int, language: string, domain: string, name: string, value: string}>
     */
    public function strings(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table; the strings must be live.
        $rows = $this->db->get_results($this->db->prepare('SELECT id, language, context, name, value FROM %i WHERE string_package_id IS NULL OR string_package_id = 0 ORDER BY context ASC, id ASC', $this->table(self::STRINGS)), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['id'] ] = [
                'id'       => (int) $row['id'],
                'language' => (string) $row['language'],
                'domain'   => (string) $row['context'],
                'name'     => (string) $row['name'],
                'value'    => (string) $row['value'],
            ];
        }
        return $out;
    }

    /**
     * Complete translations, string id => language => value.
     *
     * @return array<int, array<string, string>>
     */
    public function complete_translations(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
        $rows = $this->db->get_results($this->db->prepare('SELECT string_id, language, value FROM %i WHERE status = %d', $this->table(self::TRANSLATIONS), self::COMPLETE), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            if (null !== $row['value'] && '' !== (string) $row['value']) {
                $out[ (int) $row['string_id'] ][ (string) $row['language'] ] = (string) $row['value'];
            }
        }
        return $out;
    }

    /** Insert or complete one translation row, as WPML's icl_add_string_translation() stores it. */
    public function save_translation(int $string_id, string $language, string $value, int $translator): void
    {
        $table = $this->table(self::TRANSLATIONS);
        $data  = [ 'status' => self::COMPLETE, 'value' => $value, 'translator_id' => $translator, 'translation_date' => current_time('mysql') ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
        $id = $this->db->get_var($this->db->prepare('SELECT id FROM %i WHERE string_id = %d AND language = %s', $table, $string_id, $language));
        if (null !== $id) {
            $this->db->update($table, $data, [ 'id' => (int) $id ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
            return;
        }
        $this->db->insert($table, [ 'string_id' => $string_id, 'language' => $language ] + $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WPML String Translation's own table.
    }

    /** Remove one translation; whether a complete one was there. */
    public function delete_translation(int $string_id, string $language): bool
    {
        $table = $this->table(self::TRANSLATIONS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
        $had = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE string_id = %d AND language = %s AND status = %d', $table, $string_id, $language, self::COMPLETE));
        $this->db->delete($table, [ 'string_id' => $string_id, 'language' => $language ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
        return $had > 0;
    }

    /**
     * Recompute a string's own status from its translations into $targets
     * (the site's languages other than the string's source language).
     *
     * @param string[] $targets
     */
    public function refresh_status(int $string_id, array $targets): void
    {
        $done = 0;
        if ([] !== $targets) {
            $in = implode(', ', array_fill(0, count($targets), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %s placeholders, one per language.
            $done = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM %i WHERE string_id = %d AND status = %d AND language IN ({$in})", $this->table(self::TRANSLATIONS), $string_id, self::COMPLETE, ...$targets));
        }
        $status = 0 === $done ? self::NOT_TRANSLATED : ($done >= count($targets) ? self::COMPLETE : self::PARTIAL);
        $this->db->update($this->table(self::STRINGS), [ 'status' => $status ], [ 'id' => $string_id ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML String Translation's own table.
    }
}
