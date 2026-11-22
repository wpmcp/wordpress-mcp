<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Ops;

use WPMCP\Modules\Acf_Extended\Value\Acfe_Scalar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The listing row of one ACF Extended item (issue #1089): what an agent
 * needs to pick an options page, form or block type, without the item's
 * whole configuration (get-acfe-item reads that).
 */
final class Acfe_Item_Summary
{
    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function summarize(string $kind, array $item, string $source): array
    {
        $id  = Acfe_Scalar::int($item['ID'] ?? 0);
        $row = [
            'name'      => $this->name($kind, $item),
            'title'     => $this->text($item['page_title'] ?? $item['title'] ?? $item['label'] ?? ''),
            'active'    => ! array_key_exists('active', $item) || (bool) $item['active'],
            'source'    => $source,
            'id'        => $id,
            'stored_in' => $id > 0 ? 'database' : 'local',
        ];
        if ('options_page' === $kind) {
            return $row + [
                'menu_title'  => $this->text($item['menu_title'] ?? ''),
                'parent_slug' => Acfe_Scalar::text($item['parent_slug'] ?? ''),
                'capability'  => Acfe_Scalar::text($item['capability'] ?? ''),
                'post_id'     => is_scalar($item['post_id'] ?? null) ? $item['post_id'] : 'options',
                'autoload'    => (bool) ($item['autoload'] ?? false),
            ];
        }
        if ('form' === $kind) {
            $actions = [];
            foreach ((array) ($item['actions'] ?? []) as $action) {
                if (is_array($action) && is_string($action['action'] ?? null)) {
                    $actions[] = $action['action'];
                }
            }
            return $row + [
                'field_groups' => Acfe_Scalar::strings($item['field_groups'] ?? []),
                'actions'      => $actions,
            ];
        }
        return $row + [
            'category'        => Acfe_Scalar::text($item['category'] ?? ''),
            'description'     => $this->text($item['description'] ?? ''),
            'post_types'      => Acfe_Scalar::strings($item['post_types'] ?? []),
            'mode'            => Acfe_Scalar::text($item['mode'] ?? ''),
            'render_template' => Acfe_Scalar::text($item['render_template'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $item */
    public function name(string $kind, array $item): string
    {
        if ('options_page' === $kind && is_string($item['menu_slug'] ?? null) && '' !== $item['menu_slug']) {
            return $item['menu_slug'];
        }
        return Acfe_Scalar::text($item['name'] ?? '');
    }

    private function text(mixed $value): string
    {
        return wp_strip_all_tags(Acfe_Scalar::text($value));
    }
}
