<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What one site gate settings write will store (issue #1099): the new value
 * of each plugin option it touches, or the new builder JSON of one SeedProd
 * page, plus the bypass state the write leaves (checked for a lockout before
 * anything is written). The same plan names the snapshot, so the undo point
 * covers exactly what the write touches.
 */
final class Write_Plan
{
    /**
     * @param array<string, mixed> $options option name => new value
     * @param list<string>         $notes
     */
    public function __construct(
        public readonly array $options = [],
        public readonly ?int $post_id = null,
        public readonly ?string $post_json = null,
        public readonly ?Bypass_State $bypass_after = null,
        public readonly array $notes = [],
    ) {
    }
}
