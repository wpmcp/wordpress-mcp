<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The template types and display conditions Oxygen 6 has registered
 * (Breakdance\Themeless\ThemelessController: templateTypeCategories and
 * conditions), reduced to what a template's settings are checked against.
 * Only conditions that support 'templating' are kept, as Oxygen's template
 * manager offers only those.
 */
final class Oxygen6_Vocabulary
{
    /**
     * @param array<string,array{label:string,category:string}> $types by slug
     * @param array<string,array{label:string,category:string,operands:string[],available_for:string[]}> $conditions by slug
     */
    public function __construct(
        public readonly array $types,
        public readonly array $conditions
    ) {
    }

    /**
     * @param mixed $categories Oxygen's templateTypeCategories
     * @param mixed $conditions Oxygen's registered conditions
     */
    public static function from_registry(mixed $categories, mixed $conditions): self
    {
        $types = [];
        foreach (is_array($categories) ? $categories : [] as $name => $category) {
            $label = Oxygen6_Values::at($category, 'label');
            $label = is_string($label) ? $label : (string) $name;
            foreach (Oxygen6_Values::items(Oxygen6_Values::at($category, 'types')) as $type) {
                $slug = Oxygen6_Values::at($type, 'slug');
                if (is_string($slug) && '' !== $slug && ! isset($types[ $slug ])) {
                    $types[ $slug ] = ['label' => Oxygen6_Values::str(Oxygen6_Values::at($type, 'label') ?? $slug), 'category' => $label];
                }
            }
        }

        $known = [];
        foreach (is_array($conditions) ? $conditions : [] as $condition) {
            $slug     = Oxygen6_Values::at($condition, 'slug');
            $supports = Oxygen6_Values::at($condition, 'supports') ?? ['templating'];
            if (! is_string($slug) || '' === $slug || isset($known[ $slug ]) || ! in_array('templating', Oxygen6_Values::items($supports), true)) {
                continue;
            }
            $known[ $slug ] = [
                'label'         => Oxygen6_Values::str(Oxygen6_Values::at($condition, 'label') ?? $slug),
                'category'      => Oxygen6_Values::str(Oxygen6_Values::at($condition, 'category')),
                'operands'      => self::strings(Oxygen6_Values::at($condition, 'operands')),
                'available_for' => self::strings(Oxygen6_Values::at($condition, 'availableForType')),
            ];
        }

        return new self($types, $known);
    }

    /**
     * A registry list as strings, the way strval() reads each entry.
     *
     * @return list<string>
     */
    private static function strings(mixed $list): array
    {
        return array_values(array_map(static fn (mixed $entry): string => Oxygen6_Values::str($entry), Oxygen6_Values::items($list)));
    }

    public function has_type(string $slug): bool
    {
        return isset($this->types[ $slug ]);
    }

    /** @return array{label:string,category:string,operands:string[],available_for:string[]}|null */
    public function condition(string $slug): ?array
    {
        return $this->conditions[ $slug ] ?? null;
    }

    /**
     * The read view: every type, and every condition a template may use
     * (Custom PHP, which runs code, is left out).
     *
     * @return array{template_types:array<int,array<string,string>>,conditions:array<int,array<string,mixed>>}
     */
    public function to_array(): array
    {
        $types = [];
        foreach ($this->types as $slug => $type) {
            $types[] = ['slug' => $slug] + $type;
        }
        $conditions = [];
        foreach ($this->conditions as $slug => $condition) {
            if (Oxygen6_Settings_Validator::CUSTOM_PHP !== $slug) {
                $conditions[] = ['slug' => $slug] + $condition;
            }
        }

        return ['template_types' => $types, 'conditions' => $conditions];
    }
}
