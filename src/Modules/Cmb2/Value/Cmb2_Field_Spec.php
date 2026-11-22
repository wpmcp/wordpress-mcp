<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One CMB2 field as registered in code (issue #1089): its id (the meta key,
 * or the key inside an options page's option array), type, label, default,
 * fixed options, the flags that change how CMB2 stores it, and, for a group
 * field, its sub fields. Built from the field's config array by
 * Wp_Cmb2_Boxes.
 */
final class Cmb2_Field_Spec
{
    /**
     * @param mixed                          $default      The field's 'default', when it is not a callback.
     * @param array<string, string>          $options      A choice field's fixed option keys and labels.
     * @param array<string, Cmb2_Field_Spec> $sub_fields   A group field's sub fields, by id.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly mixed $default,
        public readonly array $options,
        public readonly bool $options_from_callback,
        public readonly bool $repeatable,
        public readonly bool $multiple,
        public readonly bool $saves,
        public readonly bool $secret,
        public readonly array $sub_fields = []
    ) {
    }

    public function is_group(): bool
    {
        return 'group' === $this->type;
    }
}
