<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot target and the write itself for a Write_Plan (issue #1099).
 */
interface Plan_Writer
{
    /** @return array{object_type: string, object_id: int|string}|null what the undo point covers */
    public function target(Write_Plan $plan): ?array;

    /** Whether applying the plan would change anything stored. */
    public function changes(Write_Plan $plan): bool;

    public function apply(Write_Plan $plan): void;
}
