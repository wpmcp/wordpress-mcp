<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Flatsome's site-wide scopes on get-builder-content, read only:
 *
 * - templates: the UX Blocks (see Flatsome_Blocks).
 * - catalog: the common UX Builder elements, where each one goes and its
 *   most used options. UX Builder registers its element definitions only
 *   inside its own editor request, so this list is kept here; a shortcode
 *   not on it is still read and edited by path like any other.
 * - design_system: not a builder scope here. Flatsome's colors, typography,
 *   header and footer options are theme options, read and written with the
 *   get-flatsome-settings / set-flatsome-settings theme operations.
 */
final class Flatsome_Design
{
    /**
     * tag => [title, kind (container|text|void), parent, options]. An option
     * is name => short description.
     *
     * @var array<string,array{0:string,1:string,2:string,3:array<string,string>}>
     */
    private const ELEMENTS = [
        'section'        => ['Section', 'container', 'top level', ['bg' => 'background image id', 'bg_color' => 'background color', 'bg_overlay' => 'overlay color', 'padding' => 'padding, e.g. 60px', 'height' => 'minimum height', 'dark' => 'true for light text', 'label' => 'admin label']],
        'row'            => ['Row', 'container', 'section or top level', ['style' => 'small, large, collapse ...', 'col_style' => 'column style', 'width' => 'full-width or custom', 'v_align' => 'top, middle, bottom, equal', 'h_align' => 'left, center, right']],
        'col'            => ['Column', 'container', 'row', ['span' => '1 to 12 grid columns', 'span__sm' => 'span on small screens', 'span__md' => 'span on medium screens', 'padding' => 'padding', 'align' => 'text alignment']],
        'row_inner'      => ['Inner row', 'container', 'col', ['style' => 'row style', 'v_align' => 'vertical alignment']],
        'col_inner'      => ['Inner column', 'container', 'row_inner', ['span' => '1 to 12 grid columns', 'span__sm' => 'span on small screens']],
        'ux_text'        => ['Text', 'text', 'col', ['font_size' => 'relative size, e.g. 1.2', 'text_align' => 'left, center, right', 'text_color' => 'text color']],
        'title'          => ['Title', 'void', 'col', ['text' => 'the title', 'style' => 'normal, center, bold ...', 'tag_name' => 'h1 to h6', 'size' => 'size in percent', 'color' => 'color']],
        'ux_image'       => ['Image', 'void', 'col', ['id' => 'attachment id', 'image_size' => 'thumbnail, medium, large, original', 'width' => 'width in percent', 'link' => 'link url', 'lightbox' => 'true to open in a lightbox']],
        'button'         => ['Button', 'void', 'col', ['text' => 'label', 'link' => 'url', 'target' => '_blank for a new tab', 'style' => 'outline, link, underline, shade ...', 'color' => 'primary, secondary, success, alert, white', 'size' => 'small, large ...', 'radius' => 'corner radius']],
        'gap'            => ['Gap', 'void', 'col', ['height' => 'height, e.g. 30px']],
        'divider'        => ['Divider', 'void', 'col', ['width' => 'width', 'height' => 'thickness', 'color' => 'color', 'align' => 'left, center, right']],
        'ux_banner'      => ['Banner', 'container', 'col or top level', ['height' => 'height, e.g. 500px', 'bg' => 'background image id', 'bg_color' => 'background color', 'bg_overlay' => 'overlay color', 'link' => 'link url']],
        'text_box'       => ['Text box', 'text', 'ux_banner', ['width' => 'width in percent', 'position_x' => '0 to 100', 'position_y' => '0 to 100', 'text_align' => 'left, center, right', 'text_color' => 'light or dark']],
        'ux_slider'      => ['Slider', 'container', 'col or top level', ['type' => 'slide, fade ...', 'bullets' => 'true or false', 'auto_slide' => 'true or false', 'timer' => 'milliseconds']],
        'tabgroup'       => ['Tabs', 'container', 'col', ['title' => 'heading', 'style' => 'line, simple, pills ...', 'align' => 'left, center, right']],
        'tab'            => ['Tab', 'text', 'tabgroup', ['title' => 'tab label']],
        'accordion'      => ['Accordion', 'container', 'col', ['title' => 'heading', 'auto_open' => 'true to open the first item']],
        'accordion-item' => ['Accordion item', 'text', 'accordion', ['title' => 'item label']],
        'block'          => ['UX Block', 'void', 'any container or top level', ['id' => 'the UX Block id or slug (scope templates lists them)']],
    ];

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return Flatsome_Blocks::templates();
        }
        if ('catalog' === $scope) {
            return self::catalog($element);
        }

        return new \WP_Error('unsupported_builder', "Flatsome's scope reads are 'templates' (UX Blocks) and 'catalog'; its colors, typography, header and footer are theme options: use theme-read get-flatsome-settings.");
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        $out = ['builder' => 'flatsome', 'scope' => 'catalog', 'plugin_active' => Flatsome_Content::theme_active(), 'plugin_version' => Flatsome_Content::theme_version()];

        if ('' !== $element) {
            if (! isset(self::ELEMENTS[ $element ])) {
                return Builder_Design_Data::element_not_found('flatsome', $element);
            }

            return $out + ['element' => self::describe($element, true)];
        }

        $elements = [];
        foreach (array_keys(self::ELEMENTS) as $tag) {
            $elements[] = self::describe($tag, false);
        }

        return $out + ['elements' => $elements];
    }

    /** @return array<string,mixed> */
    private static function describe(string $tag, bool $with_options): array
    {
        [$title, $kind, $parent, $options] = self::ELEMENTS[ $tag ];
        $item = ['tag' => $tag, 'title' => $title, 'kind' => $kind, 'parent' => $parent];
        if ($with_options) {
            $item['options'] = [];
            foreach ($options as $name => $description) {
                $item['options'][] = ['name' => $name, 'description' => $description];
            }
        }

        return $item;
    }
}
