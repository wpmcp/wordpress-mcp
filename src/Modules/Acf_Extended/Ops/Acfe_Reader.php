<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Ops;

use WPMCP\Modules\Acf_Extended\Adapter\Acfe_Source;
use WPMCP\Modules\Acf_Extended\Value\Acfe_Refusal;
use WPMCP\Modules\Acf_Extended\Value\Acfe_Scalar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ACF Extended reads (issue #1089): items of one kind listed, one item
 * read whole, and one field group's settings with what ACF Extended adds to
 * them (its 'acfe' settings array, the older top-level acfe_* keys and the
 * field group categories). Secrets are hidden in every read.
 */
final class Acfe_Reader
{
    public const CATEGORY_TAXONOMY = 'acf-field-group-category';

    public function __construct(
        private readonly Acfe_Source $source,
        private readonly Acfe_Item_Summary $summary,
        private readonly Acfe_Secret_Mask $mask
    ) {
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function list_items(array $args): array
    {
        $kind  = Acfe_Scalar::text($args['kind'] ?? '');
        $items = [];
        foreach ($this->entries($kind) as [ $item, $source ]) {
            $items[] = $this->mask->mask($this->summary->summarize($kind, $item, $source));
        }
        return [ 'kind' => $kind, 'module_active' => $this->source->module_active($kind), 'items' => $items, 'count' => count($items) ];
    }

    /** @param array<mixed> $args */
    public function item_refusal(array $args): ?Acfe_Refusal
    {
        return null === $this->find($args) ? $this->item_not_found($args) : null;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function get_item(array $args): array
    {
        $found = $this->find($args);
        if (null === $found) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $this->item_not_found($args)->to_exception();
        }
        [ $item, $source ] = $found;
        return [ 'kind' => Acfe_Scalar::text($args['kind'] ?? ''), 'name' => Acfe_Scalar::text($args['name'] ?? ''), 'source' => $source, 'item' => $this->mask->mask($item) ];
    }

    /** @param array<mixed> $args */
    public function field_group_refusal(array $args): ?Acfe_Refusal
    {
        return null === $this->field_group(Acfe_Scalar::text($args['key'] ?? '')) ? $this->group_not_found($args) : null;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    public function field_group_settings(array $args): array
    {
        $group = $this->field_group(Acfe_Scalar::text($args['key'] ?? ''));
        if (null === $group) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw $this->group_not_found($args)->to_exception();
        }
        $fields   = $this->source->fields($group);
        $settings = $group;
        unset($settings['fields']);
        $legacy = [];
        foreach ($group as $name => $value) {
            if (Acfe_Scalar::starts_with($name, 'acfe_')) {
                $legacy[ $name ] = $value;
            }
        }
        $id         = Acfe_Scalar::int($group['ID'] ?? 0);
        $categories = [];
        if ($id > 0 && taxonomy_exists(self::CATEGORY_TAXONOMY)) {
            $terms      = wp_get_post_terms($id, self::CATEGORY_TAXONOMY, [ 'fields' => 'names' ]);
            $categories = is_array($terms) ? Acfe_Scalar::strings($terms) : [];
        }

        return [
            'key'         => Acfe_Scalar::text($group['key'] ?? ''),
            'title'       => wp_strip_all_tags(Acfe_Scalar::text($group['title'] ?? '')),
            'active'      => (bool) ($group['active'] ?? true),
            'local'       => is_string($group['local'] ?? null) ? $group['local'] : ((bool) ($group['local'] ?? false) ? 'php' : false),
            'field_count' => count($fields),
            'acfe'        => $this->mask->mask(is_array($group['acfe'] ?? null) ? $group['acfe'] : []),
            'acfe_legacy' => $this->mask->mask($legacy),
            'categories'  => $categories,
            'settings'    => $this->mask->mask($settings),
        ];
    }

    /** @param array<mixed> $args */
    private function item_not_found(array $args): Acfe_Refusal
    {
        $kind = Acfe_Scalar::text($args['kind'] ?? '');
        return new Acfe_Refusal('acfe_item_not_found', sprintf('No ACF Extended %s named "%s" on this site.', str_replace('_', ' ', $kind), Acfe_Scalar::text($args['name'] ?? '')), [ 'names' => $this->names($kind) ]);
    }

    /** @param array<mixed> $args */
    private function group_not_found(array $args): Acfe_Refusal
    {
        return new Acfe_Refusal('acf_field_group_not_found', sprintf('No ACF field group with key "%s".', Acfe_Scalar::text($args['key'] ?? '')));
    }

    /** @return array<string, mixed>|null */
    private function field_group(string $key): ?array
    {
        return $this->source->field_group($key);
    }

    /**
     * @param array<mixed> $args
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function find(array $args): ?array
    {
        $kind = Acfe_Scalar::text($args['kind'] ?? '');
        $name = Acfe_Scalar::text($args['name'] ?? '');
        foreach ($this->entries($kind) as $entry) {
            if ($this->summary->name($kind, $entry[0]) === $name) {
                return $entry;
            }
        }
        return null;
    }

    /** @return string[] */
    private function names(string $kind): array
    {
        return array_map(fn (array $entry): string => $this->summary->name($kind, $entry[0]), $this->entries($kind));
    }

    /** @return array<int, array{0: array<string, mixed>, 1: string}> each item with where it comes from. */
    private function entries(string $kind): array
    {
        $type = $this->source->post_type($kind);
        $out  = [];
        foreach ($this->source->items($kind) as $item) {
            $out[] = [ $item, $type ];
        }
        if ('options_page' === $kind) {
            foreach ($this->source->ui_options_pages() as $item) {
                $out[] = [ $item, Acfe_Source::UI_OPTIONS_PAGE ];
            }
        }
        return $out;
    }
}
