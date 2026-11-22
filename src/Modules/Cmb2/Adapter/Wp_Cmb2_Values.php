<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Field_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Refusal;
use WPMCP\Modules\Cmb2\Value\Cmb2_Stored_Value;
use WPMCP\Modules\Cmb2\Value\Cmb2_Target;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where CMB2 keeps field values and how they are written (issue #1089).
 *
 * A post box stores each field in post meta under the field id (a
 * 'multiple' field as several rows); an options page box stores every
 * field in one option array named by its option key. Reads come straight
 * from that storage. Writes go through CMB2's own save: the box is pointed
 * at the object, the field's save_field() runs the field's sanitization_cb
 * or CMB2_Sanitize for its type and stores the result (an empty result
 * removes the value), and an options page's array is then saved with
 * CMB2_Options::set(), as the options page form does.
 *
 * A field whose storage a site moved elsewhere through CMB2's override
 * filters, and a network options page, is not one this store writes.
 */
#[Provides(Cmb2_Values::class)]
final class Wp_Cmb2_Values implements Cmb2_Values
{
    public function value(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): Cmb2_Stored_Value
    {
        if ($target->is_post()) {
            if (! metadata_exists('post', $target->post_id, $field->id)) {
                return new Cmb2_Stored_Value(false);
            }
            $many = $field->multiple && ! $field->repeatable;
            return new Cmb2_Stored_Value(true, get_post_meta($target->post_id, $field->id, ! $many));
        }
        $row = $this->option_row($box, $target)->value;
        return is_array($row) && array_key_exists($field->id, $row) ? new Cmb2_Stored_Value(true, $row[ $field->id ]) : new Cmb2_Stored_Value(false);
    }

    /** Whether a site moved this field's storage away from post meta or the option array. */
    public function custom_storage(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): bool
    {
        if ($target->is_post()) {
            return has_filter('cmb2_override_meta_save') || has_filter('cmb2_override_' . $field->id . '_meta_save')
                || has_filter('cmb2_override_meta_value') || has_filter('cmb2_override_' . $field->id . '_meta_value');
        }
        return $box->network || has_filter('cmb2_override_option_save_' . $target->option_key) || has_filter('cmb2_override_option_get_' . $target->option_key);
    }

    /** Save one value through the box's own field object. */
    public function save(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field, mixed $value): void
    {
        $native = $box->native;
        if (! method_exists($native, 'object_id') || ! method_exists($native, 'object_type') || ! method_exists($native, 'get_field')) {
            $refusal = new Cmb2_Refusal('cmb2_unsupported', 'This CMB2 version has no box API to save through; change the value in wp-admin.');
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $refusal->to_exception();
        }
        $native->object_type($target->object_type);
        $native->object_id($target->is_post() ? $target->post_id : $target->option_key);
        $object = $native->get_field($field->id, null, true);
        if (! is_object($object) || ! method_exists($object, 'save_field')) {
            $refusal = new Cmb2_Refusal('cmb2_unsupported', sprintf('CMB2 did not hand out the field "%s" to save through.', $field->id));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $refusal->to_exception();
        }
        $object->save_field($value);
        if (! $target->is_post()) {
            $this->save_options($target->option_key);
        }
    }

    /** What a write may change, captured before it: the field's meta, or the whole option row. */
    public function before_image(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): Cmb2_Stored_Value
    {
        return $target->is_post() ? $this->value($box, $target, $field) : $this->option_row($box, $target);
    }

    /** Put back what before_image() captured, after CMB2 refused a value. */
    public function put_back(Cmb2_Target $target, Cmb2_Field_Spec $field, Cmb2_Stored_Value $image): void
    {
        if ($target->is_post()) {
            if ($image->exists) {
                update_post_meta($target->post_id, $field->id, wp_slash($image->value));
            } else {
                delete_post_meta($target->post_id, $field->id);
            }
            return;
        }
        if ($image->exists) {
            update_option($target->option_key, $image->value);
        } else {
            delete_option($target->option_key);
        }
    }

    private function option_row(Cmb2_Box_Spec $box, Cmb2_Target $target): Cmb2_Stored_Value
    {
        $missing = new \stdClass();
        $row     = $box->network ? get_site_option($target->option_key, $missing) : get_option($target->option_key, $missing);
        return $row === $missing ? new Cmb2_Stored_Value(false, []) : new Cmb2_Stored_Value(true, $row);
    }

    /** CMB2_Options::set(): store an options page's option array after a field save updated it in memory. */
    private function save_options(string $option_key): void
    {
        if (! function_exists('cmb2_options')) {
            return;
        }
        $options = cmb2_options($option_key);
        if (is_object($options) && method_exists($options, 'set')) {
            $options->set();
        }
    }
}
