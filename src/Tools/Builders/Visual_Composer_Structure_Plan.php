<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A planned Visual Composer structural write (issue #1112), fully worked out
 * before anything is written: the new editor document, the new saved
 * markup, postmeta to set and postmeta to delete, and the element the
 * write addressed.
 */
final class Visual_Composer_Structure_Plan
{
    /**
     * @param array<string,mixed> $meta        meta key => value, as get_post_meta() returns it
     * @param list<string>        $delete_meta meta keys to delete
     */
    public function __construct(
        public readonly object $document,
        public readonly string $html,
        public readonly array $meta = [],
        public readonly array $delete_meta = [],
        public readonly ?string $path = null,
    ) {
    }
}
