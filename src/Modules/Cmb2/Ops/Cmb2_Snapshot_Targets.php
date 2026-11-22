<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Ops;

use WPMCP\Modules\Cmb2\Value\Cmb2_Request;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The undo point of a CMB2 write (issue #1089): a 'post' snapshot of the
 * post (its row, every meta value and its terms) for a post box, an
 * 'option' snapshot of the option array for an options page box. Either
 * restores exactly, so rollback-operation puts the field back, and removes
 * it again when the write created it.
 */
final class Cmb2_Snapshot_Targets
{
    /** @return array{object_type: string, object_id: int|string} */
    public function for_request(Cmb2_Request $request): array
    {
        return $request->target->is_post()
            ? [ 'object_type' => 'post', 'object_id' => $request->target->post_id ]
            : [ 'object_type' => 'option', 'object_id' => $request->target->option_key ];
    }
}
