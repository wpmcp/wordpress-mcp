<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where WPForms Pro keeps one entry (issue #1031): the wpforms_entries row
 * (primary key entry_id) and the rows of its child tables that name it by
 * entry_id, as WPForms_Entry_Fields_Handler and WPForms_Entry_Meta_Handler
 * define them. A payment (WPForms 1.8.2+ wpforms_payments, keyed by
 * entry_id) is accounting data, so an entry with one is never deleted.
 *
 * Only the table layout lives here; WPForms_Integration runs the delete
 * through the forms base's one-session db_rows path.
 */
final class WPForms_Entry_Tables
{
    public const ENTRIES = 'wpforms_entries';

    public const FIELDS = 'wpforms_entry_fields';

    public const META = 'wpforms_entry_meta';

    public const PAYMENTS = 'wpforms_payments';

    /**
     * Delete steps, children first and the entry row last, so a failure part
     * way through leaves the entry row in place.
     *
     * @return list<array{table: string, primary_key: list<string>, where: array<string, int>, optional?: bool}>
     */
    public static function delete_steps(int $entry_id): array
    {
        return [
            [ 'table' => self::FIELDS, 'primary_key' => [ 'id' ], 'where' => [ 'entry_id' => $entry_id ], 'optional' => true ],
            [ 'table' => self::META, 'primary_key' => [ 'id' ], 'where' => [ 'entry_id' => $entry_id ], 'optional' => true ],
            [ 'table' => self::ENTRIES, 'primary_key' => [ 'entry_id' ], 'where' => [ 'entry_id' => $entry_id ] ],
        ];
    }

    /** @return list<array{0: string, 1: string}> [table suffix, column naming the entry] pairs */
    public static function payment_tables(): array
    {
        return [ [ self::PAYMENTS, 'entry_id' ] ];
    }
}
