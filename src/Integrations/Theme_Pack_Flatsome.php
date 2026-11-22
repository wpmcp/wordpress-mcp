<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Flatsome theme options pack (issue #849), built by Theme_Settings_Pack.
 *
 * Storage: Flatsome's theme options are Customizer settings of type
 * theme_mod, so they live in the theme_mods row of the active stylesheet
 * (theme_mods_flatsome, or theme_mods_<child> under a child theme) and a key
 * that is not stored falls back to the theme's own default. The allowlist is
 * the general styling a site owner changes most: the brand colors (primary,
 * secondary, success, alert), the text and link colors, the heading and body
 * font families, the site width, the header height, background and width,
 * and the footer texts. Typography is one array per group (type_headings, type_texts) with
 * a font-family and a variant, so a family write seeds the array with the
 * theme default first. Logos, custom CSS and scripts, and every other key
 * stay off the list.
 *
 * Flatsome prints its Customizer CSS inline on each request, so a write
 * needs no cache refresh of its own.
 */
final class Theme_Pack_Flatsome
{
    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $mod   = static fn (string $key, $rule): array => [ 'store' => 'mods', 'path' => [ $key ], 'rule' => $rule ];
        $color = [ 'rule' => 'color' ];
        $font  = static fn (string $group, string $variant): array => [
            'store' => 'mods',
            'path'  => [ $group, 'font-family' ],
            'rule'  => [ 'rule' => 'font' ],
            'seed'  => [ 'font-family' => 'Lato', 'variant' => $variant ],
        ];

        return [
            'family'   => 'flatsome',
            'label'    => 'Flatsome',
            'tier'     => 'pro',
            'stores'   => [ 'mods' => [ 'type' => 'theme_mods' ] ],
            'settings' => [
                'color_primary'        => $mod('color_primary', $color),
                'color_secondary'      => $mod('color_secondary', $color),
                'color_success'        => $mod('color_success', $color),
                'color_alert'          => $mod('color_alert', $color),
                'color_texts'          => $mod('color_texts', $color),
                'color_links'          => $mod('color_links', $color),
                'headings_font_family' => $font('type_headings', '700'),
                'texts_font_family'    => $font('type_texts', '400'),
                'site_width'           => $mod('site_width', [ 'rule' => 'width', 'min' => 600, 'max' => 2000 ]),
                'header_height'        => $mod('header_height', [ 'rule' => 'int', 'min' => 30, 'max' => 500 ]),
                'header_bg'            => $mod('header_bg', $color),
                'header_width'         => $mod('header_width', [ 'container', 'full-width' ]),
                'footer_left_text'     => $mod('footer_left_text', [ 'rule' => 'html' ]),
                'footer_right_text'    => $mod('footer_right_text', [ 'rule' => 'html' ]),
            ],
        ];
    }
}
