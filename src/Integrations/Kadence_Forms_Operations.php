<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Kadence Blocks advanced form ops (issue #676) for the plugin-data
 * pair. Reads: list-forms, get-form (fields, messages, redirect, actions),
 * list-fields and list-notifications (the admin email and the auto email
 * to the submitter, and whether each action is on). One write, off until
 * wpmcp_enable_kadence_forms_write: update-notification changes the
 * recipients, subject, sender and message of either email and can turn it
 * on or off (its entry in _kad_form_actions). It runs under a post
 * snapshot of the form (fields and meta), so rollback-operation restores
 * the meta exactly, removing any the write created.
 *
 * Every op takes manage_options: the notification settings name the
 * addresses form data is mailed to.
 */
final class Kadence_Forms_Operations
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_kadence_forms_write';

    /** arg name => key in the notification meta. */
    private const TEXT_KEYS = [
        'email_to'   => 'emailTo',
        'subject'    => 'subject',
        'from_email' => 'fromEmail',
        'from_name'  => 'fromName',
        'reply_to'   => 'replyTo',
        'cc'         => 'cc',
        'bcc'        => 'bcc',
        'message'    => 'message',
    ];

    /** Args holding addresses: an address, a comma list of them, or a {field} placeholder. */
    private const ADDRESS_ARGS = [ 'email_to', 'from_email', 'cc', 'bcc' ];

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly Kadence_Forms_Store $store,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $form     = [ 'type' => 'object', 'properties' => [ 'form_id' => $id ], 'required' => [ 'form_id' ] ];
        $base     = [ 'capability' => self::CAPABILITY, 'requires' => $this->presence ];
        $form_obj = static fn (string $access): array => [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $refusal  = fn (array $args): ?array => $this->form_refusal((int) $args['form_id']);
        $text     = [ 'type' => 'string', 'maxLength' => 2000 ];

        return [
            'kadenceforms-list-forms'          => $base + [
                'mode'         => 'read',
                'description'  => 'List Kadence Blocks advanced forms: id, title, status, actions (email, autoEmail, redirect, ...). status filters (publish, draft, private, pending)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private', 'pending' ] ] ] ],
                'handler'      => fn (array $args): array => [ 'forms' => array_map(fn (\WP_Post $p): array => $this->summary($p), $this->store->forms(isset($args['status']) ? (string) $args['status'] : null)) ],
            ],
            'kadenceforms-get-form'            => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj('read'),
                'description'  => 'Read one Kadence Blocks advanced form: title, status, actions, fields, success and error messages, redirect',
                'input_schema' => $form,
                'validate'     => $refusal,
                'handler'      => fn (array $args): array => [ 'form' => $this->form_view((int) $args['form_id']) ],
            ],
            'kadenceforms-list-fields'         => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj('read'),
                'description'  => 'List one Kadence Blocks advanced form\'s fields: name, label, type, required, options',
                'input_schema' => $form,
                'validate'     => $refusal,
                'handler'      => fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'fields' => $this->fields((int) $args['form_id']) ],
            ],
            'kadenceforms-list-notifications'  => $base + [
                'mode'         => 'read',
                'objects'      => $form_obj('read'),
                'description'  => 'List one Kadence Blocks advanced form\'s emails: email (to the site) and autoEmail (to the submitter), each with active, recipients, subject, sender, reply-to, cc, bcc and message',
                'input_schema' => $form,
                'validate'     => $refusal,
                'handler'      => fn (array $args): array => [ 'form_id' => (int) $args['form_id'], 'notifications' => $this->notifications((int) $args['form_id']) ],
            ],
            'kadenceforms-update-notification' => $base + [
                'mode'               => 'write',
                'objects'            => $form_obj('write'),
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_kadence_forms_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Change a Kadence Blocks advanced form email: notification email or autoEmail; active turns it on or off; email_to, subject, from_email, from_name, reply_to, cc, bcc, message (addresses or {field} placeholders). Undo: rollback-operation',
                'input_schema'       => [
                    'type'                 => 'object',
                    'properties'           => [
                        'form_id'      => $id,
                        'notification' => [ 'type' => 'string', 'enum' => array_keys(Kadence_Forms_Store::NOTIFICATIONS) ],
                        'active'       => [ 'type' => 'boolean' ],
                    ] + array_fill_keys(array_keys(self::TEXT_KEYS), $text),
                    'required'             => [ 'form_id', 'notification' ],
                    'minProperties'        => 3,
                    'additionalProperties' => false,
                ],
                'validate'           => fn (array $args): ?array => $this->form_refusal((int) $args['form_id']) ?? $this->address_refusal($args),
                'snapshot'           => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['form_id'] ],
                'handler'            => fn (array $args): array => $this->update_notification($args),
            ],
        ];
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function form_refusal(int $form_id): ?array
    {
        return null === $this->store->form($form_id)
            ? [ 'code' => 'form_not_found', 'message' => sprintf('%d is not a Kadence Blocks advanced form.', $form_id), 'data' => [ 'form_id' => $form_id ] ]
            : null;
    }

    /** @return array{code: string, message: string, data: array<string, string>}|null */
    private function address_refusal(array $args): ?array
    {
        foreach (self::ADDRESS_ARGS as $key) {
            if (! isset($args[ $key ]) || '' === trim((string) $args[ $key ])) {
                continue;
            }
            foreach (explode(',', (string) $args[ $key ]) as $part) {
                $part = trim($part);
                if (! is_email($part) && 1 !== preg_match('/^\{[a-zA-Z0-9_:-]+\}$/', $part)) {
                    return [ 'code' => 'invalid_recipient', 'message' => sprintf('"%s" in %s is not an email address or a {field} placeholder.', $part, $key), 'data' => [ 'arg' => $key ] ];
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function summary(\WP_Post $post): array
    {
        return [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status, 'actions' => $this->store->actions((int) $post->ID) ];
    }

    /** @return list<array<string, mixed>> */
    private function fields(int $form_id): array
    {
        $out = [];
        foreach ($this->store->meta_array($form_id, '_kad_form_fields') as $field) {
            if (! is_array($field)) {
                continue;
            }
            $row = [
                'name'     => (string) ($field['name'] ?? ''),
                'label'    => (string) ($field['label'] ?? ''),
                'type'     => (string) ($field['type'] ?? ''),
                'required' => ! empty($field['required']),
            ];
            if (is_array($field['options'] ?? null) && [] !== $field['options']) {
                $row['options'] = array_values(array_map(static fn ($o): array => [ 'label' => (string) ($o['label'] ?? ''), 'value' => (string) ($o['value'] ?? '') ], array_filter($field['options'], 'is_array')));
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function form_view(int $form_id): array
    {
        $post = $this->store->form($form_id);
        return $this->summary($post) + [
            'fields'   => $this->fields($form_id),
            'messages' => $this->store->meta_array($form_id, '_kad_form_messages'),
            'redirect' => $this->store->meta_string($form_id, '_kad_form_redirect'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notifications(int $form_id): array
    {
        $actions = $this->store->actions($form_id);
        $out     = [];
        foreach (Kadence_Forms_Store::NOTIFICATIONS as $id => $key) {
            $meta = $this->store->meta_array($form_id, $key);
            $row  = [ 'id' => $id, 'active' => in_array($id, $actions, true) ];
            foreach (self::TEXT_KEYS as $arg => $stored) {
                $row[ $arg ] = (string) ($meta[ $stored ] ?? '');
            }
            $row['html'] = ! isset($meta['html']) || (bool) $meta['html'];
            $out[]       = $row;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function update_notification(array $args): array
    {
        $form_id      = (int) $args['form_id'];
        $notification = (string) $args['notification'];
        $meta         = $this->store->meta_array($form_id, Kadence_Forms_Store::NOTIFICATIONS[ $notification ]);
        $changed      = false;
        foreach (self::TEXT_KEYS as $arg => $stored) {
            if (array_key_exists($arg, $args)) {
                $meta[ $stored ] = 'message' === $arg ? wp_kses_post((string) $args[ $arg ]) : sanitize_text_field((string) $args[ $arg ]);
                $changed         = true;
            }
        }
        if ($changed) {
            $this->store->save_notification($form_id, $notification, $meta);
        }
        if (array_key_exists('active', $args)) {
            $actions = array_values(array_diff($this->store->actions($form_id), [ $notification ]));
            if (true === $args['active']) {
                $actions[] = $notification;
            }
            $this->store->save_actions($form_id, $actions);
        }
        clean_post_cache($form_id);

        $now = array_column($this->notifications($form_id), null, 'id');
        return [ 'form_id' => $form_id, 'notification' => $now[ $notification ] ];
    }
}
