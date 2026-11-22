<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo for the JetFormBuilder entry writes (issue #676): a db_rows
 * before-image of the record row for a read-state change (rollback-operation),
 * and, for a delete, one db_rows before-image per table in the call's
 * session, taken before any row goes, so rollback-session reinserts the
 * record and every child row with their original ids. A record with more
 * rows than a before-image holds is refused, never truncated.
 */
final class JetFormBuilder_Entry_Snapshots
{
    public function __construct(private readonly JetFormBuilder_Store $store)
    {
    }

    /** @return array<string, mixed>|null */
    public function viewed(int $id, bool $viewed): ?array
    {
        $table = Database_Guard::valid_table($this->store->table(JetFormBuilder_Store::RECORDS));
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
                'set'         => [ 'is_viewed' => $viewed ? 1 : 0 ],
                'rows'        => $before,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args    The op's args, recorded with each snapshot.
     * @param array<string, mixed> $context The dispatcher's self-snapshotting context.
     * @return array{rows: array<string, int>, operation_ids: list<string>}
     */
    public function delete(int $id, array $args, array $context): array
    {
        $steps = [];
        foreach ($this->store->delete_plan($id) as $step) {
            $table = Database_Guard::valid_table($step['table']);
            if (is_wp_error($table)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                throw new Operation_Refused('jetformbuilder_tables_missing', sprintf('JetFormBuilder\'s table %s is missing.', $step['table']));
            }
            $before = Database_Guard::before_image($table, $step['where'], Database_Guard::BEFORE_IMAGE_CAP + 1);
            if (count($before) > Database_Guard::BEFORE_IMAGE_CAP) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                throw new Operation_Refused('entry_too_large', sprintf('The record has more than %d rows in %s, more than a snapshot can hold, so it is not deleted.', Database_Guard::BEFORE_IMAGE_CAP, $table));
            }
            if ([] !== $before) {
                $steps[] = [ $table, $step, $before ];
            }
        }

        $ids  = [];
        $rows = [];
        foreach ($steps as [ $table, $step, $before ]) {
            $where = $step['where'];
            $store = $this->store;
            $out   = Safe_Mutation::run(
                [
                    'object_type'         => 'db_rows',
                    'object_id'           => $table,
                    'session_id'          => (string) $context['session_id'],
                    'tool_name'           => (string) $context['tool_name'],
                    'args'                => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                    'extra_snapshot_data' => [
                        'table'       => $table,
                        'operation'   => 'delete',
                        'primary_key' => $step['primary_key'],
                        'where'       => $where,
                        'set'         => [],
                        'rows'        => $before,
                    ],
                ],
                static function () use ($store, $table, $where, $before): int {
                    $affected = $store->delete_rows($table, $where);
                    Database_Guard::invalidate_caches($table, [ 'rows' => $before, 'where' => $where ]);
                    return $affected;
                }
            );
            $ids[]          = (string) $out['operation_id'];
            $rows[ $table ] = (int) $out['result'];
        }

        return [ 'rows' => $rows, 'operation_ids' => $ids ];
    }
}
