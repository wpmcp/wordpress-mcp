<?php

declare(strict_types=1);

namespace WPMCP\Integrations\Support;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The option_set snapshot id a module names as a write's undo target
 * (design section 1.5), built exactly as Snapshot builds it. An injectable
 * service, since module code calls no static WPMCP helper (rule S7).
 */
final class Option_Sets
{
    /** @param list<string> $names */
    public function id(array $names): string
    {
        return Snapshot::option_set_id($names);
    }
}
