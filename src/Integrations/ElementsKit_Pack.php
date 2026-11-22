<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registration glue for the ElementsKit pack (issue #1012): builds the ops
 * from their parts, for Theme_Integration::extension_operations() to merge.
 * See ElementsKit_Operations for the ops and ElementsKit_Store for the
 * storage they read and write.
 */
final class ElementsKit_Pack
{
    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $catalog = new ElementsKit_Catalog();
        $store   = new ElementsKit_Store();
        $sync    = new ElementsKit_Element_Sync();

        return (new ElementsKit_Operations(
            $catalog,
            $store,
            new ElementsKit_Planner($catalog, $store, $sync),
            new ElementsKit_Snapshot(),
            $sync
        ))->all();
    }
}
