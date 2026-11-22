<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One element node of a saved Visual Composer page (issue #1112), with its
 * byte offsets in post_content: the outer markup is [start, end), the inner
 * markup [inner_start, inner_end). `el_id` is the element id when the node
 * carries id="el-<id>", `content` whether it is a data-vce-element-content
 * node (where a container's child elements sit), `loose_text` whether text
 * other than whitespace sits directly inside it.
 */
final class Visual_Composer_Markup_Node
{
    /** @param list<Visual_Composer_Markup_Node> $children the element nodes directly inside, in order */
    public function __construct(
        public readonly string $tag,
        public readonly int $start,
        public readonly int $inner_start,
        public readonly int $inner_end,
        public readonly int $end,
        public readonly ?string $el_id,
        public readonly bool $content,
        public readonly bool $loose_text,
        public readonly array $children,
    ) {
    }
}
