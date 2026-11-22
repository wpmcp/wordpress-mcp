<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Variation Swatches ops (issue #1096) for the plugin-data pair:
 *
 *  - variationswatches-list-attributes: every global product attribute with
 *    its swatch type (WooCommerce's attribute_type column), whether that type
 *    is a swatch, its term count, sort order and archives flag, and the types
 *    on offer on this site (WooCommerce's list as the plugin extends it);
 *  - variationswatches-get-attribute: one attribute with its terms and each
 *    term's swatch colour and image (the plugin's term meta);
 *  - variationswatches-set-attribute-type: set one attribute's type, through
 *    wc_update_attribute() with every other column passed back unchanged. A
 *    type not on offer is refused: WooCommerce would save it as select. Off
 *    until wpmcp_enable_variation_swatches_write; undo: rollback-operation.
 *
 * Reads take manage_product_terms (WooCommerce's attributes screen). The
 * write takes manage_options, which the undo of a raw table row needs.
 */
final class Variation_Swatches_Operations
{
    public const READ_CAPABILITY = 'manage_product_terms';

    public const WRITE_CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_variation_swatches_write';

    private const CHECK_NOTE = 'Open a variable product that uses this attribute and check the swatches look the way you expect; undo with rollback-operation if not.';

    /**
     * @param \Closure(): (true|array<string, mixed>) $presence The plugin check every op declares.
     */
    public function __construct(
        private readonly Variation_Swatches_Store $store,
        private readonly Variation_Swatches_Snapshots $snapshots,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $enabled = Typed::bool(apply_filters('wpmcp_enable_variation_swatches_write', false));
        $base    = [ 'capability' => self::READ_CAPABILITY, 'requires' => $this->presence ];

        return [
            'variationswatches-list-attributes'    => $base + [
                'mode'         => 'read',
                'description'  => 'List WooCommerce global product attributes with their Variation Swatches type (select, color, image, button, radio...), whether it shows as swatches, term count, and the types this site offers',
                'input_schema' => [ 'type' => 'object' ],
                'handler'      => fn (): array => [
                    'attributes'     => $this->store->attributes(),
                    'types'          => $this->type_list(),
                    'writes_enabled' => $enabled,
                ],
            ],
            'variationswatches-get-attribute'      => $base + [
                'mode'         => 'read',
                'description'  => 'Read one WooCommerce product attribute with its Variation Swatches type and its terms, each with its swatch colour and image id',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'attribute_id' => $id ], 'required' => [ 'attribute_id' ] ],
                'validate'     => fn (array $args): ?array => $this->attribute_refusal(Typed::int($args['attribute_id'] ?? null)),
                'handler'      => fn (array $args): array => [ 'attribute' => $this->view(Typed::int($args['attribute_id'] ?? null)) ],
            ],
            'variationswatches-set-attribute-type' => [
                'capability'         => self::WRITE_CAPABILITY,
                'requires'           => $this->presence,
                'mode'               => 'write',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Set the Variation Swatches type of one WooCommerce product attribute (type: one of the types variationswatches-list-attributes reports, such as color, image, button, radio or select). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_variation_swatches_write filter)',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'attribute_id' => $id, 'type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 50 ] ],
                    'required'   => [ 'attribute_id', 'type' ],
                ],
                'validate'           => fn (array $args): ?array => $this->type_refusal(Typed::int($args['attribute_id'] ?? null), Typed::str($args['type'] ?? null)),
                'snapshot'           => fn (array $args): ?array => $this->snapshots->type(Typed::int($args['attribute_id'] ?? null), Typed::str($args['type'] ?? null)),
                'handler'            => fn (array $args): array => $this->set_type(Typed::int($args['attribute_id'] ?? null), Typed::str($args['type'] ?? null)),
            ],
        ];
    }

    /** @return list<array{type: string, label: string}> */
    private function type_list(): array
    {
        $out = [];
        foreach ($this->store->types() as $type => $label) {
            $out[] = [ 'type' => $type, 'label' => $label ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function view(int $id): array
    {
        $attribute          = Typed::map($this->store->attribute($id));
        $attribute['terms'] = $this->store->terms(Typed::str($attribute['taxonomy'] ?? null));
        return $attribute;
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function attribute_refusal(int $id): ?array
    {
        return null === $this->store->attribute($id)
            ? [ 'code' => 'attribute_not_found', 'message' => sprintf('%d is not a WooCommerce product attribute.', $id), 'data' => [ 'attribute_id' => $id ] ]
            : null;
    }

    /** @return array{code: string, message: string, data?: array<string, mixed>}|null */
    private function type_refusal(int $id, string $type): ?array
    {
        $refusal = $this->attribute_refusal($id);
        if (null !== $refusal) {
            return $refusal;
        }
        $types = array_keys($this->store->types());
        if (! in_array($type, $types, true)) {
            return [
                'code'    => 'unknown_type',
                'message' => sprintf('"%s" is not an attribute type this site offers (%s). WooCommerce would save it as select.', $type, implode(', ', $types)),
                'data'    => [ 'types' => $types ],
            ];
        }
        if (Typed::str($this->store->attribute($id)['type'] ?? null) === $type) {
            return [ 'code' => 'no_change', 'message' => sprintf('Attribute %d already has type %s; nothing to change.', $id, $type) ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function set_type(int $id, string $type): array
    {
        $previous = Typed::str($this->store->attribute($id)['type'] ?? null);
        $this->store->set_type($id, $type);
        return [
            'attribute'     => $this->store->attribute($id),
            'previous_type' => $previous,
            'check'         => self::CHECK_NOTE,
        ];
    }
}
