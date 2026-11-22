<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Adapter;

use WPMCP\Modules\Cmb2\Value\Cmb2_Box_Spec;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The module boundary to CMB2's box registry (issue #1089): whether CMB2 is
 * loaded and the boxes registered in code, cmb2_admin_init ones included.
 */
interface Cmb2_Boxes
{
    /** Whether CMB2 is loaded, as a plugin or bundled in a theme. */
    public function active(): bool;

    /** Whether the cmb2_admin_init boxes are in the list. */
    public function admin_boxes_loaded(): bool;

    /** @return array<string, Cmb2_Box_Spec> every box, by id, in registration order. */
    public function all(): array;

    public function get(string $id): ?Cmb2_Box_Spec;
}
