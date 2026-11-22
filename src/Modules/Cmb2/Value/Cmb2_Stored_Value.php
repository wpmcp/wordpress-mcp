<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/** A stored CMB2 value, or the row it lives in, and whether it exists at all (issue #1089). */
final class Cmb2_Stored_Value
{
    public function __construct(
        public readonly bool $exists,
        public readonly mixed $value = null
    ) {
    }
}
