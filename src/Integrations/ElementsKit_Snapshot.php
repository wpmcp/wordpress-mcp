<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The undo target of an ElementsKit switch (issue #1012): elementskit_options
 * and elementor_disabled_elements captured together as one option_set, so
 * one rollback-operation puts both back (an option the write created is
 * deleted again). Both are captured for every write, a module-only batch
 * included, because ElementsKit's own hook on Elementor's list can rewrite
 * widget_list. A plan with no changes, or a refused one, has no target.
 */
final class ElementsKit_Snapshot
{
    /** @return array{object_type: string, object_id: string}|null */
    public function target(ElementsKit_Plan $plan): ?array
    {
        if (! $plan->has_changes()) {
            return null;
        }
        return [
            'object_type' => 'option_set',
            'object_id'   => Snapshot::option_set_id([ ElementsKit_Store::OPTION, ElementsKit_Store::DISABLED ]),
        ];
    }
}
