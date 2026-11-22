<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Adapter;

use WPMCP\Api\V1\Attribute\Provides;
use WPMCP\Modules\Acf_Extended\Value\Acfe_Scalar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where ACF Extended keeps its options pages, dynamic forms and block types
 * (issue #1089), read from its own modules: acfe_get_module('options_page'),
 * ('form') and ('block_type'), whose get_items() returns every item from
 * the database (the acfe-dop, acfe-form and acfe-dbt post types, the item
 * serialized in post_content) and from local PHP or JSON files. ACF's own
 * options pages UI (the acf-ui-options-page post type, which ACF Extended
 * extends) is read from its posts the same way.
 *
 * Filters: wpmcp_acfe_active (whether ACF Extended is loaded) and
 * wpmcp_acfe_modules (the module objects keyed by module name).
 */
#[Provides(Acfe_Source::class)]
final class Wp_Acfe_Source implements Acfe_Source
{
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_acfe_active', class_exists('ACFE') || function_exists('acfe_get_module'));
    }

    /** The module object for a kind, or null when ACF Extended does not have it. */
    private function module(string $kind): ?object
    {
        $modules = [];
        if (function_exists('acfe_get_module')) {
            foreach (self::KINDS as $name) {
                $module = acfe_get_module($name);
                if (is_object($module)) {
                    $modules[ $name ] = $module;
                }
            }
        }
        /** Filter: the ACF Extended modules keyed by module name. */
        $modules = (array) apply_filters('wpmcp_acfe_modules', $modules);
        $module  = $modules[ $kind ] ?? null;
        return is_object($module) ? $module : null;
    }

    /** Whether the module for a kind is switched on (its modules/* setting). */
    public function module_active(string $kind): bool
    {
        $module = $this->module($kind);
        return null !== $module && (! method_exists($module, 'is_active') || (bool) $module->is_active());
    }

    /** @return array<int, array<string, mixed>> the module's items. */
    public function items(string $kind): array
    {
        $module = $this->module($kind);
        if (null === $module || ! $this->module_active($kind) || ! method_exists($module, 'get_items')) {
            return [];
        }
        try {
            $items = (array) $module->get_items();
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_map(static fn (array $item): array => Acfe_Scalar::map($item), array_filter($items, 'is_array')));
    }

    /** @return array<int, array<string, mixed>> ACF's UI options pages, each with ID and name prepended. */
    public function ui_options_pages(): array
    {
        $posts = get_posts([
            'post_type'        => self::UI_OPTIONS_PAGE,
            'post_status'      => [ 'publish', 'acf-disabled' ],
            'posts_per_page'   => 200,
            'orderby'          => 'menu_order title',
            'order'            => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $post) {
            $item = maybe_unserialize($post->post_content);
            $item = Acfe_Scalar::map(is_array($item) ? $item : []);
            $out[] = $item + [ 'ID' => $post->ID, 'name' => Acfe_Scalar::text($item['menu_slug'] ?? $post->post_name), 'label' => $post->post_title, 'active' => 'publish' === $post->post_status ];
        }
        return $out;
    }

    public function post_type(string $kind): string
    {
        $module = $this->module($kind);
        return null !== $module && isset($module->post_type) && is_string($module->post_type) ? $module->post_type : $kind;
    }

    /** @return array<string, mixed>|null */
    public function field_group(string $key): ?array
    {
        if ('' === $key || ! function_exists('acf_get_field_group')) {
            return null;
        }
        $group = acf_get_field_group($key);
        return is_array($group) && (bool) ($group['key'] ?? false) ? Acfe_Scalar::map($group) : null;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<int|string, mixed>
     */
    public function fields(array $group): array
    {
        return function_exists('acf_get_fields') ? (array) acf_get_fields($group) : (array) ($group['fields'] ?? []);
    }
}
