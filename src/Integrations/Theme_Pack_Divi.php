<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Divi theme options pack (issue #1011), built by Theme_Settings_Pack.
 *
 * Storage, as Divi 5.18 reads it: Divi sets $et_store_options_in_one_row, so
 * et_get_option() reads every theme option from the single et_divi option
 * array (epanel/custom_functions.php). The Customizer registers each key
 * below as et_divi[<key>] with type 'option' (functions.php), so a key that
 * is not stored falls back to Divi's own default. The allowlist is the
 * Customizer's general settings: accent and text colors, body and heading
 * fonts, content and gutter width, boxed layout, header style, header and
 * footer backgrounds, footer columns and footer credits. Logos, integration
 * code, API keys and the builder's own switches stay off the list.
 *
 * Divi caches the global $et_theme_options per request and writes its
 * Customizer CSS into static resource files, so after a write and after its
 * undo the pack drops that per-request copy and clears the static resources
 * the way Divi does on theme or plugin changes (et_core_page_resource_auto_clear).
 */
final class Theme_Pack_Divi
{
    /** The option Divi keeps every theme option in. */
    public const OPTION = 'et_divi';

    /** Header styles, from et_divi_header_style_choices(). */
    private const HEADER_STYLES = [ 'left', 'centered', 'split', 'slide', 'fullscreen' ];

    /** Footer column layouts, from et_divi_footer_column_choices(). */
    private const FOOTER_COLUMNS = [
        '1', '2', '3', '4', '5', '6',
        '_1_4__3_4', '_3_4__1_4', '_1_3__2_3', '_2_3__1_3', '_1_4__1_2', '_1_2__1_4',
        '_1_5__3_5', '_3_5__1_5', '_3_5__2_5', '_2_5__3_5', '_1_2__1_6', '_1_6__1_2',
        '_1_4_1_2_1_4', '_1_5_3_5_1_5',
    ];

    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $key   = static fn (string $name, $rule): array => [ 'store' => 'divi', 'path' => [ $name ], 'rule' => $rule ];
        $color = [ 'rule' => 'color' ];

        return [
            'family'   => 'divi',
            'label'    => 'Divi',
            'tier'     => 'pro',
            'stores'   => [ 'divi' => [ 'type' => 'option', 'option' => self::OPTION ] ],
            'settings' => [
                'accent_color'                  => $key('accent_color', $color),
                'font_color'                    => $key('font_color', $color),
                'header_color'                  => $key('header_color', $color),
                'link_color'                    => $key('link_color', $color),
                'body_font'                     => $key('body_font', [ 'rule' => 'font' ]),
                'heading_font'                  => $key('heading_font', [ 'rule' => 'font' ]),
                'body_font_size'                => $key('body_font_size', [ 'rule' => 'int', 'min' => 10, 'max' => 32 ]),
                'content_width'                 => $key('content_width', [ 'rule' => 'width', 'min' => 960, 'max' => 1920 ]),
                'gutter_width'                  => $key('gutter_width', [ 'rule' => 'int', 'min' => 1, 'max' => 4 ]),
                'boxed_layout'                  => $key('boxed_layout', [ 'rule' => 'bool' ]),
                'header_style'                  => $key('header_style', self::HEADER_STYLES),
                'primary_nav_bg'                => $key('primary_nav_bg', $color),
                'footer_bg'                     => $key('footer_bg', $color),
                'footer_columns'                => $key('footer_columns', self::FOOTER_COLUMNS),
                'custom_footer_credits'         => $key('custom_footer_credits', [ 'rule' => 'html' ]),
                'disable_custom_footer_credits' => $key('disable_custom_footer_credits', [ 'rule' => 'bool' ]),
            ],
            'refresh'  => static function (): void {
                $GLOBALS['et_theme_options'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Divi's own per-request cache, dropped so et_get_option() rereads et_divi.
                if (function_exists('et_core_page_resource_auto_clear')) {
                    et_core_page_resource_auto_clear();
                }
            },
        ];
    }
}
