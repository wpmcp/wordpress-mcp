<?php

declare(strict_types=1);

namespace WPMCP\Modules\Hustle;

use WPMCP\Api\V1\Attribute\Opt_In_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The switch that turns on the Hustle writes (issue #1091).
 */
#[Opt_In_Gate(filter: self::FILTER, destructive: false, labels: 'opt_in_labels')]
final class Opt_In
{
    public const FILTER = 'wpmcp_enable_hustle_write';

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return [self::FILTER => __('Hustle: content, schedule, switch and display condition changes', 'wpmcp')];
    }
}
