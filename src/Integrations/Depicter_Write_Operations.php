<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Depicter writes of issue #1091 on the plugin-data pair, beside the
 * reads in Depicter_Pack (#898):
 *  - depicter-rename-document: the document's name, under a db_rows
 *    before-image of its row (rollback-operation);
 *  - depicter-set-status: publish (the document goes to publish and a
 *    published revision is added, as DocumentRepository::saveEditorData()
 *    does) or draft (the document and every revision go to draft). The
 *    revisions are captured as a set keyed by the document
 *    (Plugin_Table_Rows_Snapshot kind depicter_revisions), so the undo also
 *    removes the revision a publish added, and the document row under its
 *    own before-image; one rollback-session undoes both;
 *  - depicter-set-display-rules: the document's `rules` row of
 *    depicter_meta, replaced whole, captured as the document's meta rows
 *    (kind depicter_meta), so the undo also removes a rules row the write
 *    created.
 * Off until wpmcp_enable_depicter_write; manage_options, which restoring
 * those rows takes.
 */
final class Depicter_Write_Operations
{
    public const OPT_IN_FILTER = 'wpmcp_enable_depicter_write';

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly Depicter_Write_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $write = [
            'mode'               => 'write',
            'capability'         => 'manage_options',
            'requires'           => $this->presence,
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_depicter_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'depicter-rename-document'   => $write + [
                'description'  => 'Rename a Depicter slider, popup or bar (name). Undo: rollback-operation. Disabled by default (wpmcp_enable_depicter_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ] ],
                    'required'             => [ 'id', 'name' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->document_refusal($args) ?? self::name_refusal($args),
                'snapshot'     => fn (array $args): ?array => $this->row_snapshot(self::id($args), [ 'name' => self::name($args) ]),
                'handler'      => fn (array $args): array => $this->rename(self::id($args), self::name($args)),
            ],
            'depicter-set-status'        => $write + [
                'self_snapshotting' => true,
                'description'       => 'Publish a Depicter document (status publish: it goes live and a published revision is saved, as Depicter\'s Publish does) or unpublish it (status draft). Undo: rollback-session. Disabled by default (wpmcp_enable_depicter_write filter)',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ] ],
                    'required'             => [ 'id', 'status' ],
                    'additionalProperties' => false,
                ],
                'validate'          => fn (array $args): ?array => $this->document_refusal($args) ?? $this->status_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->set_status(self::id($args), 'publish' === ($args['status'] ?? null), $args, $context),
            ],
            'depicter-set-display-rules' => $write + [
                'description'  => 'Replace a Depicter document\'s display rules (rules, the object depicter-get-document returns). Undo: rollback-operation. Disabled by default (wpmcp_enable_depicter_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'rules' => [ 'type' => 'object', 'maxProperties' => 50 ] ],
                    'required'             => [ 'id', 'rules' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->document_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('depicter_meta', 'meta', [ self::id($args) ]),
                ],
                'handler'      => fn (array $args): array => $this->rules(self::id($args), Typed::map($args['rules'] ?? null)),
            ],
        ];
    }

    /** @param array<mixed> $args */
    private static function id(array $args): int
    {
        return Typed::int($args['id'] ?? null);
    }

    /** @param array<mixed> $args */
    private static function name(array $args): string
    {
        return sanitize_text_field(Typed::str($args['name'] ?? null));
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, int>}|null
     */
    private function document_refusal(array $args): ?array
    {
        $id = self::id($args);
        return null === $this->store->document($id)
            ? [ 'code' => 'document_not_found', 'message' => sprintf('%d is not a Depicter document (a revision is not one either).', $id), 'data' => [ 'id' => $id ] ]
            : null;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, int>}|null
     */
    private static function name_refusal(array $args): ?array
    {
        return '' === trim(self::name($args)) ? [ 'code' => 'invalid_setting', 'message' => 'The name cannot be empty.', 'data' => [ 'id' => self::id($args) ] ] : null;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, int|string>}|null
     */
    private function status_refusal(array $args): ?array
    {
        $status = Typed::str($args['status'] ?? null);
        $now    = Typed::str(Typed::map($this->store->document(self::id($args)))['status'] ?? null);
        return $now === $status ? [ 'code' => 'no_change', 'message' => sprintf('Document %d is already %s.', self::id($args), $status), 'data' => [ 'id' => self::id($args), 'status' => $status ] ] : null;
    }

    /**
     * A db_rows before-image of the document row.
     *
     * @param array<string, int|string> $set
     * @return array<string, mixed>|null
     */
    private function row_snapshot(int $id, array $set): ?array
    {
        $table = Database_Guard::valid_table($this->store->table(Depicter_Write_Store::DOCUMENTS));
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
            'extra_snapshot_data' => [ 'table' => $table, 'operation' => 'update', 'primary_key' => [ 'id' ], 'where' => $where, 'set' => $set, 'rows' => $before ],
        ];
    }

    /** @return array<string, mixed> */
    private function view(int $id): array
    {
        $doc = Typed::map($this->store->document($id));
        return [ 'id' => $id, 'name' => Typed::str($doc['name'] ?? null), 'status' => Typed::str($doc['status'] ?? null) ];
    }

    /** @return array<string, mixed> */
    private function rename(int $id, string $name): array
    {
        $this->store->rename($id, $name);
        return [ 'document' => $this->view($id) ];
    }

    /**
     * @param array<mixed>         $args
     * @param array<mixed>         $context The dispatcher's self-snapshotting context.
     * @return array{result: array<string, mixed>, operation_ids: list<string>}
     */
    private function set_status(int $id, bool $publish, array $args, array $context): array
    {
        $base  = [
            'session_id' => Typed::str($context['session_id'] ?? null),
            'tool_name'  => Typed::str($context['tool_name'] ?? null),
            'args'       => [ 'operation' => Typed::str($context['operation'] ?? null), 'args' => $args ],
        ];
        $store = $this->store;
        $ids   = [];
        $out   = Safe_Mutation::run(
            $base + [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('depicter_revisions', 'documents', [ $id ]) ],
            static function () use ($store, $id, $publish): bool {
                $publish ? $store->add_revision($id) : $store->draft_revisions($id);
                return true;
            }
        );
        $ids[] = Typed::str($out['operation_id'] ?? null);
        $row   = $this->row_snapshot($id, [ 'status' => $publish ? 'publish' : 'draft' ]);
        if (null !== $row) {
            $out   = Safe_Mutation::run(
                $base + $row,
                static function () use ($store, $id, $publish): bool {
                    $store->set_status($id, $publish ? 'publish' : 'draft');
                    return true;
                }
            );
            $ids[] = Typed::str($out['operation_id'] ?? null);
        }
        return [ 'result' => [ 'document' => $this->view($id) ], 'operation_ids' => array_values(array_filter($ids, static fn (string $op): bool => '' !== $op)) ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function rules(int $id, array $rules): array
    {
        $this->store->save_rules($id, $rules);
        return [ 'id' => $id, 'rules' => $rules ];
    }
}
