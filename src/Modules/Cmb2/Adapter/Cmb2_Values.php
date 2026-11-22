<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Adapter;

use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Field_Spec;
use WPMCP\Modules\Cmb2\Value\Cmb2_Stored_Value;
use WPMCP\Modules\Cmb2\Value\Cmb2_Target;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The module boundary to where CMB2 keeps field values (issue #1089): post
 * meta for a post box, one option array for an options page box, written
 * through CMB2's own field save.
 */
interface Cmb2_Values
{
    public function value(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): Cmb2_Stored_Value;

    /** Whether a site moved this field's storage away from post meta or the option array. */
    public function custom_storage(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): bool;

    /** Save one value through the box's own field object; refuses with a Handler_Refusal when CMB2 offers no way to. */
    public function save(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field, mixed $value): void;

    /** What a write may change, captured before it: the field's meta, or the whole option row. */
    public function before_image(Cmb2_Box_Spec $box, Cmb2_Target $target, Cmb2_Field_Spec $field): Cmb2_Stored_Value;

    /** Put back what before_image() captured, after CMB2 refused a value. */
    public function put_back(Cmb2_Target $target, Cmb2_Field_Spec $field, Cmb2_Stored_Value $image): void;
}
