<?php

declare(strict_types=1);

namespace WPMCP\Modules\NextGen;

use WPMCP\Api\V1\Attribute\Opt_In_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The switch that turns on the NextGEN Gallery writes (issue #705). Each write is
 * undoable, so the gate is not destructive.
 */
#[Opt_In_Gate(filter: self::FILTER, destructive: false, labels: 'opt_in_labels')]
final class Opt_In
{
    public const FILTER = 'wpmcp_enable_nextgen_write';

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [self::FILTER => __('NextGEN Gallery: image order and image writes', 'wpmcp')];
    }
}
