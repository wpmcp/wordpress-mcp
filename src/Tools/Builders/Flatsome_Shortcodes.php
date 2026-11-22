<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Flatsome (UX Builder) dialect of the shared shortcode layout parser.
 * Flatsome stores a page, and a UX Block (the `blocks` post type), as nested
 * shortcodes in post_content: section > row > col, with row_inner >
 * col_inner (and the deeper row_inner_1 / col_inner_1 ... levels) nested in
 * a column, and elements (ux_text, ux_image, button, gap, title, ux_banner >
 * text_box, block ...) inside. Addressing, parsing and byte-preserving edits
 * are the parent's; only the writing conventions differ:
 *  - layout and wrapper tags are always written with a closing tag, while a
 *    new element with no body is written as an opening tag alone
 *    (`[gap height="30px"]`), as UX Builder writes it;
 *  - attribute values are written raw, so the characters that would end the
 *    value or the tag are written as the HTML entities `&quot;`, `&#91;` and
 *    `&#93;`.
 */
final class Flatsome_Shortcodes extends WPBakery_Shortcodes
{
    protected const CONTAINER_TAG = '/^(?:section|row|row_inner(?:_[12])?|col|col_inner(?:_[12])?|ux_text|ux_banner|text_box|ux_slider|ux_banner_grid|col_grid|ux_stack|tabgroup|tab|accordion|accordion-item|lightbox)$/';

    protected const ATTR_ENCODING = ['"' => '&quot;', '[' => '&#91;', ']' => '&#93;'];

    protected const SELF_CLOSE_EMPTY = false;
}
