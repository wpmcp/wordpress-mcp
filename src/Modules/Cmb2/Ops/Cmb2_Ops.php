<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Contract\Operation_Provider;
use WPMCP\Integrations\Support\Plugin_Refusals;
use WPMCP\Modules\Cmb2\Adapter\Cmb2_Boxes;
use WPMCP\Modules\Cmb2\Value\Cmb2_Refusal;
use WPMCP\Modules\Cmb2\Value\Cmb2_Request;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The CMB2 operations on the theme pair (issue #1089): their schemas, and
 * the validate, snapshot and handler methods wired to the services that do
 * the work. No new top-level tool.
 *
 * Reads: list-cmb2-boxes and get-cmb2-values. Write, off until the site
 * opts in (wpmcp_enable_cmb2_write): set-cmb2-value, one existing field,
 * snapshotted and undone by rollback-operation.
 *
 * operations() is metadata only; each method key names an instance method,
 * run on the instance the container builds with the services injected. The
 * contributor declares no Requires, so the ops stay listed while CMB2 is
 * missing (CMB2 bundled in a theme counts) and answer cmb2_inactive.
 */
#[Operations(dispatcher: 'theme', host: 'CMB2', summary: 'CMB2 boxes and field values')]
#[Opt_In_Gate(filter: self::ENABLE_FILTER, destructive: false, labels: 'opt_in_labels')]
final class Cmb2_Ops implements Operation_Provider
{
    public const ENABLE_FILTER = 'wpmcp_enable_cmb2_write';

    public function __construct(
        private readonly Cmb2_Boxes $registry,
        private readonly Cmb2_Request_Resolver $resolver,
        private readonly Cmb2_Reader $reader,
        private readonly Cmb2_Writer $writer,
        private readonly Cmb2_Snapshot_Targets $snapshots,
        private readonly Plugin_Refusals $refusals
    ) {
    }

    /** @return array<string, array<string, mixed>> op name => definition. */
    public static function operations(): array
    {
        $where = [
            'box'        => [ 'type' => 'string', 'minLength' => 1, 'description' => 'The box id (list-cmb2-boxes)' ],
            'post_id'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The post, for a post box' ],
            'option_key' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'For an options page box with more than one option key' ],
        ];

        return [
            'list-cmb2-boxes' => [
                'mode'         => 'read',
                'capability'   => 'edit_posts',
                'description'  => 'List the CMB2 boxes registered in code (those registered on cmb2_admin_init too): id, title, object type (post, options-page, term, user, comment), post types or option keys, and each field\'s id, type, label and whether set-cmb2-value can write it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'object_type' => [ 'type' => 'string', 'enum' => [ 'post', 'options-page', 'term', 'user', 'comment' ] ] ],
                ],
                'requires'     => 'requires',
                'handler'      => 'list_boxes',
            ],
            'get-cmb2-values' => [
                'mode'         => 'read',
                'capability'   => 'edit_posts',
                'description'  => 'Read a CMB2 box\'s field values for one post (post_id) or for its options page: id, type, label, value (an unsaved field reads as its default, is_default:true), the options of a choice field and whether set-cmb2-value can write it. Keys, tokens, licences and password fields are never read out. Narrow with fields (field ids)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $where + [ 'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 200 ] ],
                    'required'   => [ 'box' ],
                ],
                'objects'      => [ 'post_id' => 'post' ],
                'requires'     => 'requires',
                'validate'     => 'read_refusal',
                'handler'      => 'read_values',
            ],
            'set-cmb2-value'  => [
                'mode'               => 'write',
                'capability'         => 'edit_posts',
                'description'        => 'Set ONE existing field of a CMB2 box, for a post (post_id) or its options page, through the field\'s own CMB2 save and sanitization. The value is checked for the field\'s type first: text, textarea and wysiwyg (a string; empty clears), email, url (http or https), colorpicker (hex), checkbox (true/false), select and radio (one of the field\'s options), multicheck (a list of them). Unknown field ids, secrets, groups, files, repeatable fields and other types are refused. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_cmb2_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_cmb2_write', false),
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => $where + [
                        'field' => [ 'type' => 'string', 'minLength' => 1 ],
                        'value' => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'array' ] ],
                    ],
                    'required'   => [ 'box', 'field', 'value' ],
                ],
                'objects'            => [ 'post_id' => 'post' ],
                'requires'           => 'requires',
                'validate'           => 'write_refusal',
                'snapshot'           => 'write_target',
                'handler'            => 'write_value',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [ self::ENABLE_FILTER => __('CMB2: field value writes', 'wpmcp') ];
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public function requires(): bool|array
    {
        return $this->registry->active() ? true : $this->refusals->make('cmb2_inactive', 'CMB2');
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function list_boxes(array $args): array
    {
        return $this->reader->list_boxes($args);
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function read_refusal(array $args): ?array
    {
        return $this->refusal($this->resolver->read($args));
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function read_values(array $args): array
    {
        return $this->reader->values($this->resolved($this->resolver->read($args)));
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function write_refusal(array $args): ?array
    {
        return $this->refusal($this->resolver->write($args));
    }

    /**
     * @param array<mixed> $args
     * @return array{object_type: string, object_id: int|string}|null
     */
    public function write_target(array $args): ?array
    {
        $resolved = $this->resolver->write($args);
        return $resolved instanceof Cmb2_Request ? $this->snapshots->for_request($resolved) : null;
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $context the dispatcher's context (operation_id).
     * @return array<string, mixed>
     */
    public function write_value(array $args, array $context = []): array
    {
        return $this->writer->write($this->resolved($this->resolver->write($args)), $context);
    }

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function refusal(Cmb2_Request|Cmb2_Refusal $resolved): ?array
    {
        return $resolved instanceof Cmb2_Refusal ? $resolved->to_array() : null;
    }

    private function resolved(Cmb2_Request|Cmb2_Refusal $resolved): Cmb2_Request
    {
        if ($resolved instanceof Cmb2_Refusal) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $resolved->to_exception();
        }
        return $resolved;
    }
}
