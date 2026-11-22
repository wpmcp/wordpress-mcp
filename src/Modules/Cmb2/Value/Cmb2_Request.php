<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A resolved CMB2 call (issue #1089): the box, where its values live and,
 * for a write, the field and the clean value to save.
 */
final class Cmb2_Request
{
    /** @param string[] $field_ids the fields a read is narrowed to; empty for all. */
    public function __construct(
        public readonly Cmb2_Box_Spec $box,
        public readonly Cmb2_Target $target,
        public readonly array $field_ids = [],
        public readonly ?Cmb2_Field_Spec $field = null,
        public readonly mixed $value = null
    ) {
    }
}
