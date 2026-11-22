<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Modules\Cmb2\Adapter\Cmb2_Boxes;
use WPMCP\Modules\Cmb2\Adapter\Cmb2_Values;
use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Request;
use WPMCP\Modules\Cmb2\Value\Cmb2_Scalar;
use WPMCP\Modules\Cmb2\Value\Cmb2_Secret_Policy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The CMB2 reads (issue #1089): the boxes with their fields, and one box's
 * values for a post or an options page. An unsaved field reads as its
 * default (is_default:true); secrets read as Cmb2_Secret_Policy::HIDDEN.
 */
final class Cmb2_Reader
{
    public function __construct(
        private readonly Cmb2_Boxes $registry,
        private readonly Cmb2_Values $store,
        private readonly Cmb2_Field_Policy $policy,
        private readonly Cmb2_Secret_Policy $secrets
    ) {
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function list_boxes(array $args): array
    {
        $type  = Cmb2_Scalar::text($args['object_type'] ?? '');
        $boxes = [];
        foreach ($this->registry->all() as $box) {
            if ('' !== $type && $box->object_type !== $type) {
                continue;
            }
            if (Cmb2_Box_Spec::OPTIONS_PAGE === $box->object_type && '' !== $box->capability && ! current_user_can($box->capability)) {
                continue;
            }
            $fields = [];
            foreach ($box->fields as $field) {
                $row = [ 'id' => $field->id, 'type' => $field->type, 'name' => $field->name, 'writable' => $this->policy->writable($field) ];
                if ($field->secret) {
                    $row['secret'] = true;
                }
                if ($field->repeatable) {
                    $row['repeatable'] = true;
                }
                $fields[] = $row;
            }
            $boxes[] = [
                'id'              => $box->id,
                'title'           => $box->title,
                'object_type'     => $box->object_type,
                'post_types'      => $box->post_types,
                'option_keys'     => $box->option_keys,
                'registered_on'   => $box->registered_on,
                'values_readable' => $box->values_readable(),
                'field_count'     => count($fields),
                'fields'          => $fields,
            ];
        }
        return [ 'boxes' => $boxes, 'count' => count($boxes), 'admin_boxes_loaded' => $this->registry->admin_boxes_loaded() ];
    }

    /** @return array<string, mixed> */
    public function values(Cmb2_Request $request): array
    {
        $rows = [];
        foreach ($request->box->fields as $field) {
            if ([] !== $request->field_ids && ! in_array($field->id, $request->field_ids, true)) {
                continue;
            }
            $stored = $this->store->value($request->box, $request->target, $field);
            $row    = [
                'id'         => $field->id,
                'type'       => $field->type,
                'name'       => $field->name,
                'value'      => $this->secrets->mask($field, $stored->exists ? $stored->value : $field->default),
                'default'    => $field->secret ? null : $field->default,
                'is_default' => ! $stored->exists,
                'writable'   => $this->policy->writable($field) && ! $this->store->custom_storage($request->box, $request->target, $field),
            ];
            if ($field->secret) {
                $row['secret'] = true;
            }
            if ($this->policy->is_choice($field) && ! $field->options_from_callback) {
                $row['options'] = $this->policy->listed_options($field);
            }
            $rows[] = $row;
        }

        $out = [ 'box' => $request->box->id, 'object_type' => $request->target->object_type ];
        if ($request->target->is_post()) {
            $out['post_id'] = $request->target->post_id;
        } else {
            $out['option_key'] = $request->target->option_key;
        }
        return $out + [ 'fields' => $rows, 'count' => count($rows) ];
    }
}
