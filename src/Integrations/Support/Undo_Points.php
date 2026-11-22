<?php

declare(strict_types=1);

namespace WPMCP\Integrations\Support;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Voids the undo point of a write that was refused after its snapshot was
 * persisted (design section 1.5), exactly as Snapshot_Store removes it. An
 * injectable service, since module code calls no static WPMCP helper (rule S7).
 */
final class Undo_Points
{
    public function void(string $operation_id): void
    {
        Snapshot_Store::delete_operation($operation_id);
    }
}
