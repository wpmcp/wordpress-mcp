<?php

declare(strict_types=1);

namespace WPMCP\Integrations\Support;

use WPMCP\Integrations\Operation_Refused;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The refusal a module's operation handler throws when a write turns out not
 * to be possible after its validate() passed (design section 1.5). It is an
 * Operation_Refused, so Integration_Dispatcher answers it with the same
 * top-level error envelope as every other refusal.
 */
final class Handler_Refusal extends Operation_Refused
{
}
