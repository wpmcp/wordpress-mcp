<?php

declare(strict_types=1);

namespace WPMCP\Modules\Variation_Swatches;

use WPMCP\Api\V1\Attribute\Opt_In_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The switch that turns on the Variation Swatches for WooCommerce writes (issue #1096).
 */
#[Opt_In_Gate(filter: self::FILTER, destructive: false, labels: 'opt_in_labels')]
final class Opt_In
{
    public const FILTER = 'wpmcp_enable_variation_swatches_write';

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [self::FILTER => __('Variation Swatches for WooCommerce: attribute swatch type', 'wpmcp')];
    }
}
