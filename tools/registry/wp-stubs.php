<?php

/**
 * The few WordPress functions an attributed class may touch while the
 * registry generator reflects it in a WordPress-free CLI process: the
 * translation passthroughs (opt-in label methods call __()) and
 * apply_filters returning its value. Nothing here runs in production.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wpmcp-registry-no-wordpress/');
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}
