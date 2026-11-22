<?php

declare(strict_types=1);

namespace WPMCP\Modules\Product_Feed;

use WPMCP\Api\V1\Attribute\Opt_In_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The switch that turns on the Product Feed Pro for WooCommerce writes (issue #1096).
 */
#[Opt_In_Gate(filter: self::FILTER, destructive: true, labels: 'opt_in_labels')]
final class Opt_In
{
    public const FILTER = 'wpmcp_enable_product_feed_write';

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [self::FILTER => __('Product Feed Pro: feed refresh (not undoable)', 'wpmcp')];
    }
}
