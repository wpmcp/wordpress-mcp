<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Modules\Cmb2\Adapter\Cmb2_Boxes;
use WPMCP\Modules\Cmb2\Adapter\Cmb2_Values;
use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Refusal;
use WPMCP\Modules\Cmb2\Value\Cmb2_Request;
use WPMCP\Modules\Cmb2\Value\Cmb2_Scalar;
use WPMCP\Modules\Cmb2\Value\Cmb2_Target;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns a CMB2 call's arguments into a Cmb2_Request or a Cmb2_Refusal
 * (issue #1089), before anything is read or written: the box must exist
 * and be a post or options page box the user may edit, the post must be
 * one the box shows on, the fields must be the box's own (never created),
 * and a write's field and value must pass Cmb2_Field_Policy and actually
 * change something.
 */
final class Cmb2_Request_Resolver
{
    public function __construct(
        private readonly Cmb2_Boxes $registry,
        private readonly Cmb2_Values $store,
        private readonly Cmb2_Field_Policy $policy
    ) {
    }

    /** @param array<mixed> $args */
    public function read(array $args): Cmb2_Request|Cmb2_Refusal
    {
        $located = $this->locate($args, 'read');
        if ($located instanceof Cmb2_Refusal) {
            return $located;
        }
        [ $box, $target ] = $located;
        $only    = Cmb2_Scalar::strings($args['fields'] ?? []);
        $unknown = array_values(array_diff($only, array_keys($box->fields)));
        if ([] !== $unknown) {
            return new Cmb2_Refusal('unknown_field', sprintf('Not a field of the CMB2 box "%s": %s.', $box->id, implode(', ', $unknown)), [ 'unknown' => $unknown, 'fields' => array_keys($box->fields) ]);
        }
        return new Cmb2_Request($box, $target, $only);
    }

    /** @param array<mixed> $args */
    public function write(array $args): Cmb2_Request|Cmb2_Refusal
    {
        $located = $this->locate($args, 'write');
        if ($located instanceof Cmb2_Refusal) {
            return $located;
        }
        [ $box, $target ] = $located;
        $id = Cmb2_Scalar::text($args['field'] ?? '');
        if (! isset($box->fields[ $id ])) {
            return new Cmb2_Refusal('unknown_field', sprintf('"%s" is not a field of the CMB2 box "%s"; fields are never created.', $id, $box->id), [ 'fields' => array_keys($box->fields) ]);
        }
        $field  = $box->fields[ $id ];
        $reason = $this->policy->not_writable_reason($field);
        if (null === $reason && $this->store->custom_storage($box, $target, $field)) {
            $reason = 'the site stores it somewhere else through CMB2\'s override filters, or it is a network option, so no exact undo can be taken';
        }
        if (null !== $reason) {
            return new Cmb2_Refusal('field_not_writable', sprintf('The CMB2 field "%s" cannot be written here: %s.', $id, $reason), [ 'field' => $id, 'type' => $field->type ]);
        }
        $clean = $this->policy->clean($field, $args['value'] ?? null);
        if (null === $clean) {
            return new Cmb2_Refusal('invalid_value', sprintf('The value is not valid for the CMB2 %s field "%s".', $field->type, $id), $this->policy->value_hint($field));
        }
        $stored = $this->store->value($box, $target, $field);
        if ($stored->exists ? $this->same($stored->value, $clean) : $this->empty($clean)) {
            return new Cmb2_Refusal('no_change', sprintf('The CMB2 field "%s" already has that value. Nothing was written and no undo point was taken.', $id));
        }
        return new Cmb2_Request($box, $target, [ $id ], $field, $clean);
    }

    /**
     * The box and where its values live, or a refusal.
     *
     * @param array<mixed> $args
     * @return array{0: Cmb2_Box_Spec, 1: Cmb2_Target}|Cmb2_Refusal
     */
    private function locate(array $args, string $access): array|Cmb2_Refusal
    {
        $id  = Cmb2_Scalar::text($args['box'] ?? '');
        $box = $this->registry->get($id);
        if (null === $box) {
            return new Cmb2_Refusal('cmb2_box_not_found', sprintf('No CMB2 box with id "%s" is registered on this site.', $id), [ 'boxes' => array_keys($this->registry->all()) ]);
        }
        if (! $box->values_readable()) {
            return new Cmb2_Refusal('cmb2_object_type_unsupported', sprintf('The CMB2 box "%s" edits %s objects; this op reads and writes post and options page boxes only.', $box->id, $box->object_type), [ 'object_type' => $box->object_type ]);
        }

        if (Cmb2_Box_Spec::OPTIONS_PAGE === $box->object_type) {
            if ('' !== $box->capability && ! current_user_can($box->capability)) {
                return new Cmb2_Refusal('operation_denied', sprintf('The CMB2 options page "%s" requires the "%s" capability.', $box->id, $box->capability), [ 'reason' => 'capability' ]);
            }
            $key = Cmb2_Scalar::text($args['option_key'] ?? '');
            if ('' === $key) {
                if (1 !== count($box->option_keys)) {
                    return new Cmb2_Refusal('option_key_required', sprintf('The CMB2 options page "%s" has %d option keys; name one with option_key.', $box->id, count($box->option_keys)), [ 'option_keys' => $box->option_keys ]);
                }
                $key = $box->option_keys[0];
            } elseif (! in_array($key, $box->option_keys, true)) {
                return new Cmb2_Refusal('unknown_option_key', sprintf('"%s" is not an option key of the CMB2 options page "%s".', $key, $box->id), [ 'option_keys' => $box->option_keys ]);
            }
            return [ $box, Cmb2_Target::option($key) ];
        }

        $post_id = Cmb2_Scalar::int($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new Cmb2_Refusal('post_id_required', sprintf('The CMB2 box "%s" stores its values per post; name the post with post_id.', $box->id), [ 'post_types' => $box->post_types ]);
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return new Cmb2_Refusal('post_not_found', sprintf('No post with ID %d.', $post_id));
        }
        if (! in_array($post->post_type, $box->post_types, true)) {
            return new Cmb2_Refusal('cmb2_box_not_for_post', sprintf('The CMB2 box "%s" is shown on %s, not on the %s post %d.', $box->id, implode(', ', $box->post_types), $post->post_type, $post_id), [ 'post_types' => $box->post_types ]);
        }
        if (! current_user_can('write' === $access ? 'edit_post' : 'read_post', $post_id)) {
            return new Cmb2_Refusal('operation_denied', sprintf('You cannot %s post %d.', 'write' === $access ? 'edit' : 'read', $post_id), [ 'reason' => 'capability' ]);
        }
        return [ $box, Cmb2_Target::post($post_id) ];
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }
        return $a === $b;
    }

    private function empty(mixed $value): bool
    {
        return '' === $value || [] === $value;
    }
}
