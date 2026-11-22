<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Jetpack Forms ops (issue #676) for the plugin-data pair. Reads:
 * list-forms (each form or page responses came from, with inbox, spam,
 * trash and unread counts), list-entries (newest first, every value
 * MASKED) and get-entry (one in full). Writes, off until
 * wpmcp_enable_jetpack_forms_write:
 *  - update-entry-status moves a response between inbox, spam and trash
 *    and marks it read or unread, the two columns Jetpack's own inbox
 *    changes, under a post snapshot (rollback-operation). Jetpack's Akismet
 *    reporting on a spam change does not run;
 *  - delete-entry deletes the response post after confirm:true, under a
 *    post snapshot, so rollback-operation brings it back at its id.
 * Every op takes manage_options: responses are personal data.
 */
final class Jetpack_Forms_Operations
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_jetpack_forms_write';

    private const MAX_PAGE_SIZE = 100;

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly Jetpack_Forms_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $entry  = [ 'type' => 'object', 'properties' => [ 'entry_id' => $id ], 'required' => [ 'entry_id' ] ];
        $base   = [ 'capability' => self::CAPABILITY, 'requires' => $this->presence ];
        $write  = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_jetpack_forms_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $exists = fn (array $args): ?array => $this->entry_refusal((int) $args['entry_id']);
        $snap   = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['entry_id'] ];

        return [
            'jetpackforms-list-forms'          => $base + [
                'mode'         => 'read',
                'description'  => 'List the Jetpack forms that have responses: id (the form or the page it is on), title, type, and inbox, spam, trash and unread counts',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (): array => [ 'forms' => $this->forms() ],
            ],
            'jetpackforms-list-entries'        => $base + [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'List Jetpack Forms responses newest first: id, form_id, status, read, date and every value MASKED (jetpackforms-get-entry returns one in full). form_id filters, status inbox (default), spam or trash; page_size (max 100) and offset. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => $id,
                        'status'    => [ 'type' => 'string', 'enum' => array_keys(Jetpack_Forms_Store::STATUSES) ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->list_entries($args),
            ],
            'jetpackforms-get-entry'           => $base + [
                'mode'         => 'read',
                'description'  => 'One Jetpack Forms response in full: status, read, date, subject, every field (label, value, type), IP address, user agent and the page it came from. Personal data',
                'input_schema' => $entry,
                'validate'     => $exists,
                'handler'      => fn (array $args): array => [ 'entry' => $this->view($this->store->entry((int) $args['entry_id']), true) ],
            ],
            'jetpackforms-update-entry-status' => $write + [
                'mode'         => 'write',
                'description'  => 'Move a Jetpack Forms response to inbox, spam or trash (status) and/or mark it read or unread (read). Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'entry_id' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => array_keys(Jetpack_Forms_Store::STATUSES) ],
                        'read'     => [ 'type' => 'boolean' ],
                    ],
                    'required'      => [ 'entry_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => $exists,
                'snapshot'     => $snap,
                'handler'      => fn (array $args): array => $this->update($args),
            ],
            'jetpackforms-delete-entry'        => $write + [
                'mode'            => 'destructive',
                'description'     => 'Permanently delete a Jetpack Forms response. Uploaded files stay. Needs confirm:true. Undo: rollback-operation brings it back at its id',
                'input_schema'    => $entry,
                'validate'        => $exists,
                'confirm_preview' => fn (array $args): array => $this->delete_preview((int) $args['entry_id']),
                'snapshot'        => $snap,
                'handler'         => fn (array $args): array => [ 'entry_id' => (int) $args['entry_id'], 'deleted' => $this->store->delete((int) $args['entry_id']) ],
            ],
        ];
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function entry_refusal(int $entry_id): ?array
    {
        return null === $this->store->entry($entry_id)
            ? [ 'code' => 'entry_not_found', 'message' => sprintf('%d is not a Jetpack Forms response.', $entry_id), 'data' => [ 'entry_id' => $entry_id ] ]
            : null;
    }

    /** @return list<array<string, mixed>> */
    private function forms(): array
    {
        $out = [];
        foreach ($this->store->sources() as $id => $counts) {
            $post  = $id > 0 ? get_post($id) : null;
            $out[] = [
                'id'     => $id,
                'title'  => $post instanceof \WP_Post ? (string) $post->post_title : '',
                'type'   => $post instanceof \WP_Post ? (string) $post->post_type : '',
                'counts' => $counts,
            ];
        }
        return $out;
    }

    /** @return list<array{label: string, value: mixed, type: string}> */
    private static function fields(array $content): array
    {
        $out = [];
        foreach ((array) ($content['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['label'])) {
                $out[] = [ 'label' => (string) $field['label'], 'value' => $field['value'] ?? '', 'type' => (string) ($field['type'] ?? '') ];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function view(\WP_Post $post, bool $full): array
    {
        $content = $this->store->content($post);
        $out     = [
            'id'      => (int) $post->ID,
            'form_id' => (int) $post->post_parent,
            'status'  => (string) (array_search((string) $post->post_status, Jetpack_Forms_Store::STATUSES, true) ?: $post->post_status),
            'read'    => 'closed' === $post->comment_status,
            'date'    => (string) $post->post_date,
            'format'  => [] === $content ? 'legacy' : 'json',
            'fields'  => self::fields($content),
        ];
        if ($full) {
            $out += [
                'subject'     => (string) ($content['subject'] ?? ''),
                'ip_address'  => (string) ($content['ip'] ?? ''),
                'user_agent'  => (string) ($content['user_agent'] ?? ''),
                'request_url' => (string) ($content['request_url'] ?? ''),
            ];
            if ([] === $content) {
                $out['text'] = (string) $post->post_content;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function list_entries(array $args): array
    {
        $size   = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $page   = $this->store->entries(isset($args['form_id']) ? (int) $args['form_id'] : null, (string) ($args['status'] ?? 'inbox'), $size, $offset);
        $rows   = array_map(function (\WP_Post $post): array {
            $row           = $this->view($post, false);
            $row['fields'] = array_map(static function (array $f): array {
                $f['value'] = Masking::value($f['value']);
                return $f;
            }, $row['fields']);
            return $row;
        }, $page['posts']);
        $seen   = $offset + count($rows);

        return [ 'entries' => $rows, 'total' => $page['total'], 'masked' => true, 'next_offset' => $seen < $page['total'] ? $seen : null ];
    }

    /** @return array<string, mixed> */
    private function update(array $args): array
    {
        $id       = (int) $args['entry_id'];
        $previous = $this->view($this->store->entry($id), false);
        $this->store->update(
            $id,
            isset($args['status']) ? Jetpack_Forms_Store::STATUSES[ (string) $args['status'] ] : null,
            isset($args['read']) ? (bool) $args['read'] : null
        );
        $now = $this->view($this->store->entry($id), false);
        return [ 'entry_id' => $id, 'status' => $now['status'], 'read' => $now['read'], 'previous' => [ 'status' => $previous['status'], 'read' => $previous['read'] ] ];
    }

    /** @return array{message: string, data: array<string, mixed>} */
    private function delete_preview(int $entry_id): array
    {
        $post   = $this->store->entry($entry_id);
        $values = count(self::fields($this->store->content($post)));
        return [
            'message' => sprintf('It permanently deletes Jetpack Forms response %d (%d stored values) from form %d. Undo: rollback-operation.', $entry_id, $values, (int) $post->post_parent),
            'data'    => [ 'entry_id' => $entry_id, 'form_id' => (int) $post->post_parent, 'values' => $values ],
        ];
    }
}
