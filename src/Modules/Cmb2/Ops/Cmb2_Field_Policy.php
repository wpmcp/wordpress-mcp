<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Modules\Cmb2\Value\Cmb2_Field_Spec;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which CMB2 fields a write accepts and what a valid value looks like for
 * each (issue #1089). The allowlist is the field types whose value is one
 * scalar or a list of fixed options: text and textarea types (a string),
 * email (an address), url (an http or https URL), colour (a hex value),
 * checkbox (true or false), select and radio (one of the field's options)
 * and multicheck (a list of them). An empty string clears a text value, as
 * CMB2's own save does. Groups, files, dates, taxonomy fields, code
 * textareas, repeatable and 'multiple' fields, fields whose options come
 * from a callback, fields CMB2 is told not to save, and secrets are refused.
 */
final class Cmb2_Field_Policy
{
    private const TEXT        = [ 'text', 'text_small', 'text_medium', 'textarea', 'textarea_small', 'wysiwyg' ];
    private const SINGLE      = [ 'select', 'radio', 'radio_inline' ];
    private const MULTI       = [ 'multicheck', 'multicheck_inline' ];
    private const OTHER       = [ 'text_email', 'text_url', 'colorpicker', 'checkbox' ];
    private const MAX_TEXT    = 65535;
    private const MAX_OPTIONS = 100;

    /** Why a field cannot be written, or null when it can. */
    public function not_writable_reason(Cmb2_Field_Spec $field): ?string
    {
        if ($field->secret) {
            return 'it holds a secret (a password, key, token or licence)';
        }
        if (! $field->saves) {
            return 'CMB2 is told not to save it (save_field is false)';
        }
        if (! in_array($field->type, array_merge(self::TEXT, self::SINGLE, self::MULTI, self::OTHER), true)) {
            return sprintf('its type "%s" is not one this op can validate', $field->type);
        }
        if ($field->repeatable || $field->multiple) {
            return 'a repeatable or multiple field is not supported';
        }
        if ($this->is_choice($field) && ($field->options_from_callback || [] === $field->options)) {
            return 'its options come from a callback, so the value cannot be checked against them';
        }
        return null;
    }

    public function writable(Cmb2_Field_Spec $field): bool
    {
        return null === $this->not_writable_reason($field);
    }

    public function is_choice(Cmb2_Field_Spec $field): bool
    {
        return in_array($field->type, self::SINGLE, true) || in_array($field->type, self::MULTI, true);
    }

    /**
     * The value as the field's form would post it, or null when it is not
     * valid for the field's type. Mixed: a JSON argument of any type in,
     * the string or list the field's form would post out.
     */
    public function clean(Cmb2_Field_Spec $field, mixed $value): mixed
    {
        if ('checkbox' === $field->type) {
            if (true === $value || 1 === $value || '1' === $value || 'on' === $value || 'true' === $value) {
                return 'on';
            }
            return (false === $value || 0 === $value || '0' === $value || '' === $value || 'false' === $value) ? '' : null;
        }
        if (in_array($field->type, self::MULTI, true)) {
            if (! is_array($value) || array_values($value) !== $value) {
                return null;
            }
            $list = [];
            foreach ($value as $item) {
                if (! is_scalar($item) || is_bool($item) || ! array_key_exists((string) $item, $field->options)) {
                    return null;
                }
                $list[] = (string) $item;
            }
            return array_values(array_unique($list));
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) > self::MAX_TEXT) {
            return null;
        }
        if (in_array($field->type, self::SINGLE, true)) {
            return array_key_exists($value, $field->options) ? $value : null;
        }
        if ('' === trim($value)) {
            return '';
        }
        if ('text_email' === $field->type) {
            return false !== is_email(trim($value)) ? trim($value) : null;
        }
        if ('text_url' === $field->type) {
            $scheme = strtolower((string) wp_parse_url(trim($value), PHP_URL_SCHEME));
            return (in_array($scheme, [ 'http', 'https' ], true) && false !== filter_var(trim($value), FILTER_VALIDATE_URL)) ? trim($value) : null;
        }
        if ('colorpicker' === $field->type) {
            return 1 === preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim($value)) ? trim($value) : null;
        }
        return $value;
    }

    /** @return array<string, mixed> what a valid value looks like for a field. */
    public function value_hint(Cmb2_Field_Spec $field): array
    {
        $hint = [ 'field' => $field->id, 'type' => $field->type ];
        if ($this->is_choice($field)) {
            $hint['options'] = array_slice(array_keys($field->options), 0, self::MAX_OPTIONS);
        }
        return $hint;
    }

    /** @return array<string, string> a choice field's options, capped. */
    public function listed_options(Cmb2_Field_Spec $field): array
    {
        return array_slice($field->options, 0, self::MAX_OPTIONS, true);
    }
}
