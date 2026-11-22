<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The JetFormBuilder ops (issue #676) for the plugin-data pair: schemas,
 * refusals and the shape of each answer. Storage is JetFormBuilder_Store's,
 * undo JetFormBuilder_Entry_Snapshots'.
 *
 * Reads: list-forms (entry and unread counts), get-form and list-fields
 * (fields from the form's jet-forms/* blocks), list-entries (every value
 * MASKED, no IP, user agent or referrer) and get-entry (one in full; it does
 * not mark the record read, unlike JetFormBuilder's own record screen).
 * Writes, off until wpmcp_enable_jetformbuilder_write:
 *  - update-entry-status marks a record read or unread (is_viewed, the one
 *    state JetFormBuilder's records table changes; its status column is the
 *    outcome of the submit and is left alone). Undo: rollback-operation;
 *  - delete-entry removes the record and its rows of records_fields,
 *    records_actions, records_errors, tokens_to_records and user_journey,
 *    as JetFormBuilder's own delete cascades them, after confirm:true. A
 *    record linked to a payment is refused. Undo: rollback-session.
 * Every op takes manage_options: records are personal data, and
 * JetFormBuilder's own records screen asks for it.
 */
final class JetFormBuilder_Operations
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_jetformbuilder_write';

    private const MAX_PAGE_SIZE = 100;

    /**
     * @param \Closure(): (true|array<string, mixed>) $presence The plugin and tables check every op declares.
     */
    public function __construct(
        private readonly JetFormBuilder_Store $store,
        private readonly JetFormBuilder_Entry_Snapshots $snapshots,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $form     = [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ];
        $entry    = [ 'type' => 'object', 'properties' => [ 'entry_id' => $id ], 'required' => [ 'entry_id' ] ];
        $base     = [ 'capability' => self::CAPABILITY, 'requires' => $this->presence ];
        $form_obj = [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ];
        $write    = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_jetformbuilder_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'jetformbuilder-list-forms'          => $base + [
                'mode'         => 'read',
                'description'  => 'List JetFormBuilder forms: id, title, status, shortcode, entries and unread counts. status filters (publish, draft, private, pending)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private', 'pending' ] ] ] ],
                'handler'      => fn (array $args): array => $this->list_forms(isset($args['status']) ? (string) $args['status'] : null),
            ],
            'jetformbuilder-get-form'            => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'Read one JetFormBuilder form: title, status, shortcode, counts and its fields in form order',
                'input_schema' => $form,
                'validate'     => fn (array $args): ?array => $this->form_refusal((int) $args['form_id']),
                'handler'      => fn (array $args): array => [ 'form' => $this->form_view((int) $args['form_id'], true) ],
            ],
            'jetformbuilder-list-fields'         => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'List one JetFormBuilder form\'s fields in form order: name (the key its value is stored under), type, label, required, options',
                'input_schema' => $form,
                'validate'     => fn (array $args): ?array => $this->form_refusal((int) $args['form_id']),
                'handler'      => fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'fields' => $this->field_list((int) $args['form_id']) ],
            ],
            'jetformbuilder-list-entries'        => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj,
                'description'  => 'List a JetFormBuilder form\'s records newest first: id, status, viewed, date and every value MASKED (jetformbuilder-get-entry returns one in full). page_size (default 20, max 100) and offset. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => $id,
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->form_refusal((int) $args['form_id']),
                'handler'      => fn (array $args): array => $this->list_entries($args),
            ],
            'jetformbuilder-get-entry'           => $base + [
                'mode'         => 'read',
                'description'  => 'One JetFormBuilder record in full: status, viewed, date, user, every value with its field label, IP address, user agent and referrer. Does not mark it read. Personal data',
                'input_schema' => $entry,
                'validate'     => fn (array $args): ?array => $this->entry_refusal((int) $args['entry_id']),
                'handler'      => fn (array $args): array => [ 'entry' => $this->entry_view((int) $args['entry_id']) ],
            ],
            'jetformbuilder-update-entry-status' => $write + [
                'mode'         => 'write',
                'description'  => 'Mark a JetFormBuilder record read or unread (viewed). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => $id, 'viewed' => [ 'type' => 'boolean' ] ],
                    'required'   => [ 'entry_id', 'viewed' ],
                ],
                'validate'     => fn (array $args): ?array => $this->entry_refusal((int) $args['entry_id']),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->viewed((int) $args['entry_id'], (bool) $args['viewed']),
                'handler'      => fn (array $args): array => $this->set_viewed((int) $args['entry_id'], (bool) $args['viewed']),
            ],
            'jetformbuilder-delete-entry'        => $write + [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'description'       => 'Permanently delete a JetFormBuilder record with its values, action results and errors. Refused when the record has a payment. Uploaded files stay on disk. Needs confirm:true. Undo: rollback-session reinserts every row',
                'input_schema'      => $entry,
                'validate'          => fn (array $args): ?array => $this->delete_refusal((int) $args['entry_id']),
                'confirm_preview'   => fn (array $args): array => $this->delete_preview((int) $args['entry_id']),
                'handler'           => fn (array $args, array $context): array => $this->delete((int) $args['entry_id'], $args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function form_refusal(int $form_id): ?array
    {
        return null === $this->store->form($form_id)
            ? [ 'code' => 'form_not_found', 'message' => sprintf('%d is not a JetFormBuilder form.', $form_id), 'data' => [ 'form_id' => $form_id ] ]
            : null;
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function entry_refusal(int $entry_id): ?array
    {
        return null === $this->store->record($entry_id)
            ? [ 'code' => 'entry_not_found', 'message' => sprintf('JetFormBuilder has no record %d.', $entry_id), 'data' => [ 'entry_id' => $entry_id ] ]
            : null;
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function delete_refusal(int $entry_id): ?array
    {
        $refused = $this->entry_refusal($entry_id);
        if (null === $refused && $this->store->has_payment($entry_id)) {
            return [
                'code'    => 'entry_has_payments',
                'message' => 'This record is linked to a payment. Financial records are not deleted here.',
                'data'    => [ 'entry_id' => $entry_id ],
            ];
        }
        return $refused;
    }

    // ------------------------------------------------------------ reads

    /** @return list<array<string, mixed>> */
    private function field_list(int $form_id): array
    {
        $form = $this->store->form($form_id);
        return null === $form ? [] : array_map(static fn (JetFormBuilder_Field $f): array => $f->to_array(), $this->store->fields($form));
    }

    /** @return array<string, mixed> */
    private function form_view(int $form_id, bool $with_fields, ?array $counts = null): array
    {
        $post   = $this->store->form($form_id);
        $counts = $counts ?? ($this->store->counts([ $form_id ])[ $form_id ] ?? [ 'entries' => 0, 'unread' => 0 ]);
        $out    = [
            'id'        => $form_id,
            'title'     => null === $post ? '' : (string) $post->post_title,
            'status'    => null === $post ? '' : (string) $post->post_status,
            'shortcode' => sprintf('[jet_fb_form form_id="%d"]', $form_id),
            'entries'   => $counts['entries'],
            'unread'    => $counts['unread'],
        ];
        if ($with_fields) {
            $out['fields'] = $this->field_list($form_id);
        }
        return $out;
    }

    /** @return array{forms: list<array<string, mixed>>} */
    private function list_forms(?string $status): array
    {
        $posts  = $this->store->forms($status);
        $ids    = array_map(static fn (\WP_Post $p): int => (int) $p->ID, $posts);
        $counts = $this->store->counts($ids);
        return [ 'forms' => array_map(fn (int $id): array => $this->form_view($id, false, $counts[ $id ]), $ids) ];
    }

    /** @return array<string, mixed> */
    private static function summary(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'form_id'    => (int) $row['form_id'],
            'status'     => (string) ($row['status'] ?? ''),
            'viewed'     => 1 === (int) ($row['is_viewed'] ?? 0),
            'user_id'    => (int) ($row['user_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param list<array{name: string, type: string, value: mixed}> $values
     * @return list<array{name: string, label: string, type: string, value: mixed}>
     */
    private function labelled(int $form_id, array $values): array
    {
        $form   = $this->store->form($form_id);
        $labels = [];
        foreach (null === $form ? [] : $this->store->fields($form) as $field) {
            $labels[ $field->name ] = $field->label;
        }
        return array_map(static fn (array $v): array => [ 'name' => $v['name'], 'label' => $labels[ $v['name'] ] ?? '', 'type' => $v['type'], 'value' => $v['value'] ], $values);
    }

    /** @return array<string, mixed> */
    private function list_entries(array $args): array
    {
        $form_id = (int) $args['form_id'];
        $size    = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? 20)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));
        $page    = $this->store->records($form_id, $size, $offset);
        $values  = $this->store->values(array_map(static fn (array $r): int => (int) $r['id'], $page['rows']));
        $entries = [];
        foreach ($page['rows'] as $row) {
            $fields    = array_map(static function (array $field): array {
                $field['value'] = Masking::value($field['value']);
                return $field;
            }, $this->labelled($form_id, $values[ (int) $row['id'] ] ?? []));
            $entries[] = self::summary($row) + [ 'fields' => $fields ];
        }
        $seen = $offset + count($page['rows']);

        return [ 'form_id' => $form_id, 'entries' => $entries, 'total' => $page['total'], 'masked' => true, 'next_offset' => $seen < $page['total'] ? $seen : null ];
    }

    /** @return array<string, mixed> */
    private function entry_view(int $entry_id): array
    {
        $row = (array) $this->store->record($entry_id);
        return self::summary($row) + [
            'fields'     => $this->labelled((int) $row['form_id'], $this->store->values([ $entry_id ])[ $entry_id ] ?? []),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'user_agent' => (string) ($row['user_agent'] ?? ''),
            'referrer'   => (string) ($row['referrer'] ?? ''),
        ];
    }

    // ------------------------------------------------------------ writes

    /** @return array<string, mixed> */
    private function set_viewed(int $entry_id, bool $viewed): array
    {
        $previous = self::summary((array) $this->store->record($entry_id));
        $this->store->set_viewed($entry_id, $viewed);
        return [ 'entry' => self::summary((array) $this->store->record($entry_id)), 'previous' => [ 'viewed' => $previous['viewed'] ] ];
    }

    /** @return array{message: string, data: array<string, mixed>} */
    private function delete_preview(int $entry_id): array
    {
        $row    = (array) $this->store->record($entry_id);
        $form   = $this->store->form((int) $row['form_id']);
        $values = $this->store->count_values($entry_id);
        return [
            'message' => sprintf(
                'It permanently deletes record %d of JetFormBuilder form "%s" (%d) with %d stored values. Uploaded files stay on disk. Undo: rollback-session.',
                $entry_id,
                null === $form ? '' : (string) $form->post_title,
                (int) $row['form_id'],
                $values
            ),
            'data'    => [ 'entry_id' => $entry_id, 'form_id' => (int) $row['form_id'], 'values' => $values ],
        ];
    }

    /** @return array{result: array<string, mixed>, operation_ids: list<string>} */
    private function delete(int $entry_id, array $args, array $context): array
    {
        $summary = self::summary((array) $this->store->record($entry_id));
        $done    = $this->snapshots->delete($entry_id, $args, $context);
        return [ 'result' => [ 'deleted' => $summary, 'rows' => $done['rows'] ], 'operation_ids' => $done['operation_ids'] ];
    }
}
