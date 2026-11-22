<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rebuilds GreenShift's per-post head stylesheet after a write (issue #1009).
 *
 * GreenShift prints each block's own CSS while the block renders
 * (gspb_greenShift_block_inline_styles). Its editor also posts the page's
 * CSS to the _gspb_post_css post meta on save (REST
 * greenshift/v1/css_settings), and gspb_save_inline_css() enqueues that meta
 * in the head of the post's view; nothing on the server rebuilds it. After a
 * write the meta is rebuilt here from the blocks the post now holds, in
 * document order (inner blocks after their parent, as the editor walks
 * them), collected the way GreenShift's renderer collects each block's CSS:
 *  - a block with CSSRender: its styleAttributes rendered for .{localId}
 *    through gspb_render_style_attributes(), then the css and selector css
 *    of its local dynamicGClasses;
 *  - any other block: its inlineCssStyles.
 * With no CSS left the meta is deleted. When a block needs
 * gspb_render_style_attributes() and it is not available, the stored meta
 * is left as it is rather than replaced by a partial rebuild.
 *
 * A rollback does not come through here: the post snapshot puts the meta
 * back byte for byte, including CSS the editor built from sources the
 * server cannot see.
 *
 * Tags are stripped from each block's CSS, as GreenShift does when it
 * prints it, so stored CSS cannot close the style element it lands in.
 */
final class Block_Suite_GreenShift_Css
{
    public const META_KEY = '_gspb_post_css';

    /**
     * @param \Closure|null $render_style_attributes GreenShift's gspb_render_style_attributes(), or null when it is not loaded
     */
    public function __construct(
        private readonly ?\Closure $render_style_attributes = null,
        private readonly string $namespace = 'greenshift-blocks/'
    ) {
    }

    /** The builder wired to GreenShift's own style renderer, when it is loaded. */
    public static function for_site(): self
    {
        return new self(function_exists('gspb_render_style_attributes') ? static fn (mixed ...$args): mixed => gspb_render_style_attributes(...$args) : null);
    }

    /**
     * Rebuild the post's meta from its content: 'stored', 'deleted', or
     * 'kept' when the CSS could not be built in full.
     */
    public function rebuild(int $post_id): string
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return 'kept';
        }
        $css = $this->css_for(array_values(parse_blocks($post->post_content)));
        if (null === $css) {
            return 'kept';
        }
        if ('' === $css) {
            delete_post_meta($post_id, self::META_KEY);
            return 'deleted';
        }
        update_post_meta($post_id, self::META_KEY, wp_slash($css));
        return 'stored';
    }

    /**
     * The CSS of every GreenShift block in a parsed block tree, in document
     * order, or null when a block's CSS cannot be built here.
     *
     * @param array<mixed> $blocks
     */
    public function css_for(array $blocks): ?string
    {
        $css = '';
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $name = $block['blockName'] ?? '';
            if (is_string($name) && str_starts_with($name, $this->namespace)) {
                $own = $this->block_css(is_array($block['attrs'] ?? null) ? $block['attrs'] : []);
                if (null === $own) {
                    return null;
                }
                $css .= $own;
            }
            $inner = $this->css_for(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);
            if (null === $inner) {
                return null;
            }
            $css .= $inner;
        }
        return $css;
    }

    /** @param array<mixed> $attrs */
    private function block_css(array $attrs): ?string
    {
        if (! isset($attrs['CSSRender']) || false === (bool) $attrs['CSSRender']) {
            $own = $attrs['inlineCssStyles'] ?? null;
            return is_string($own) ? wp_strip_all_tags($own) : '';
        }

        $css = '';
        $local_id = $attrs['localId'] ?? null;
        if (isset($attrs['styleAttributes']) && [] !== $attrs['styleAttributes'] && '' !== $attrs['styleAttributes'] && is_string($local_id) && '' !== $local_id) {
            if (null === $this->render_style_attributes) {
                return null;
            }
            $rendered = ($this->render_style_attributes)($attrs['styleAttributes'], '.' . $local_id, '', $attrs['enableSpecificity'] ?? false);
            $css     .= is_string($rendered) ? $rendered : '';
        }
        foreach ((array) ($attrs['dynamicGClasses'] ?? []) as $class) {
            if (! is_array($class) || 'local' !== ($class['type'] ?? '')) {
                continue;
            }
            $css .= is_string($class['css'] ?? null) ? $class['css'] : '';
            foreach ((array) ($class['selectors'] ?? []) as $selector) {
                $css .= is_array($selector) && is_string($selector['css'] ?? null) ? $selector['css'] : '';
            }
        }
        return wp_strip_all_tags($css);
    }
}
