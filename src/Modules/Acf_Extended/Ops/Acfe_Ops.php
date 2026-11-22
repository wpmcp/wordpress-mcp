<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Ops;

use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Contract\Operation_Provider;
use WPMCP\Integrations\Support\Plugin_Refusals;
use WPMCP\Modules\Acf_Extended\Adapter\Acfe_Source;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ACF Extended operations on the theme pair (issue #1089):
 * list-acfe-items, get-acfe-item and get-acfe-field-group-settings, all
 * reads. ACF Extended's items are saved through its own module UI, which
 * also writes their local PHP and JSON sync files, so no write is offered.
 *
 * operations() is metadata only; each method key names an instance method,
 * run on the instance the container builds with the services injected. The
 * contributor declares no Requires, so the ops stay listed while ACF
 * Extended is missing and answer acfe_inactive.
 */
#[Operations(dispatcher: 'theme', host: 'ACF Extended', summary: 'ACF Extended options pages, forms and block types')]
final class Acfe_Ops implements Operation_Provider
{
    public function __construct(
        private readonly Acfe_Source $source,
        private readonly Acfe_Reader $reader,
        private readonly Plugin_Refusals $refusals
    ) {
    }

    /** @return array<string, array<string, mixed>> op name => definition. */
    public static function operations(): array
    {
        $kind = [ 'type' => 'string', 'enum' => Acfe_Source::KINDS, 'description' => 'options_page (ACF Extended and ACF UI options pages), form (dynamic forms) or block_type (dynamic block types)' ];

        return [
            'list-acfe-items'               => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List ACF Extended options pages, dynamic forms or block types (kind): name, title, active, where it is stored (database or local file), and per kind the menu and post_id of an options page, the field groups and action types of a form, or the category, post types and template of a block type. Options pages include ACF\'s own UI options pages',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'kind' => $kind ], 'required' => [ 'kind' ] ],
                'requires'     => 'requires',
                'handler'      => 'list_items',
            ],
            'get-acfe-item'                 => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read one ACF Extended options page, form or block type by kind and name: its whole configuration (a form\'s actions, settings and messages, say), keys, tokens and passwords hidden',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'kind' => $kind, 'name' => [ 'type' => 'string', 'minLength' => 1 ] ],
                    'required'   => [ 'kind', 'name' ],
                ],
                'requires'     => 'requires',
                'validate'     => 'item_refusal',
                'handler'      => 'get_item',
            ],
            'get-acfe-field-group-settings' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read an ACF field group\'s settings by key with the ones ACF Extended adds: its acfe settings (display title, permissions, note, custom meta, autosync), older acfe_* keys and field group categories, secrets hidden. The fields themselves: acf-read get-field-group',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'key' => [ 'type' => 'string', 'minLength' => 1 ] ], 'required' => [ 'key' ] ],
                'requires'     => 'requires',
                'validate'     => 'field_group_refusal',
                'handler'      => 'field_group_settings',
            ],
        ];
    }

    /** @return true|array{code: string, message: string, data: array<string, string>} */
    public function requires(): bool|array
    {
        return $this->source->active() ? true : $this->refusals->make('acfe_inactive', 'ACF Extended');
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function list_items(array $args): array
    {
        return $this->reader->list_items($args);
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function item_refusal(array $args): ?array
    {
        return $this->reader->item_refusal($args)?->to_array();
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function get_item(array $args): array
    {
        return $this->reader->get_item($args);
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    public function field_group_refusal(array $args): ?array
    {
        return $this->reader->field_group_refusal($args)?->to_array();
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function field_group_settings(array $args): array
    {
        return $this->reader->field_group_settings($args);
    }
}
