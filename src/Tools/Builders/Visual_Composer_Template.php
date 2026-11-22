<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A saved Visual Composer template as it is stored (issue #1112): its
 * editor document, the markup the editor rendered for it, and the CSS
 * postmeta the editor compiled for it (key => value as get_post_meta()
 * returns it; a key the template lacks is left out).
 */
final class Visual_Composer_Template
{
    /** @param array<string,mixed> $css_meta */
    public function __construct(
        public readonly int $id,
        public readonly ?object $document,
        public readonly string $html,
        public readonly array $css_meta,
    ) {
    }
}
