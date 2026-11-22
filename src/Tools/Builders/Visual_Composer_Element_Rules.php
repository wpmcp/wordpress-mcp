<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The containment rules of the installed Visual Composer elements (issue
 * #1112), read from the settings each element bundle registers (see
 * Visual_Composer_Design::settings()). The page itself holds the
 * RootElements group (Visual Composer 45.16.4, public/dist/wp.bundle.js:
 * rootContainerFor ["RootElements"]).
 */
final class Visual_Composer_Element_Rules
{
    public const ROOT_GROUP = 'RootElements';

    /** @var \Closure(string): ?array<string,mixed> */
    private readonly \Closure $settings;

    /** @var array<string,?Visual_Composer_Element_Rule> */
    private array $cache = [];

    /** @param (\Closure(string): ?array<string,mixed>)|null $settings an element tag's bundle settings, or null when unknown */
    public function __construct(?\Closure $settings = null)
    {
        $this->settings = $settings ?? static function (string $tag): ?array {
            $all = Visual_Composer_Design::elements();
            if (! isset($all[ $tag ])) {
                return null;
            }

            return Visual_Composer_Design::settings($all[ $tag ]['dir'] . '/public/dist/element.bundle.js', $tag);
        };
    }

    /** The page's own rule: it holds root elements. */
    public function root(): Visual_Composer_Element_Rule
    {
        return new Visual_Composer_Element_Rule('', [], [self::ROOT_GROUP], '');
    }

    /** The rule of an element type, or null when its bundle cannot be read. */
    public function for_tag(string $tag): ?Visual_Composer_Element_Rule
    {
        if (array_key_exists($tag, $this->cache)) {
            return $this->cache[ $tag ];
        }
        $settings = '' === $tag ? null : ($this->settings)($tag);

        return $this->cache[ $tag ] = is_array($settings) ? $this->rule($tag, $settings) : null;
    }

    /**
     * @param array<string,mixed> $settings an element bundle's settings: each
     *                                       setting is an array whose 'value' holds it
     */
    private function rule(string $tag, array $settings): ?Visual_Composer_Element_Rule
    {
        $related = $this->value($settings, 'relatedTo');
        if (! is_array($related)) {
            return null;
        }
        $parent = $this->value($settings, 'parentWrapper');

        return new Visual_Composer_Element_Rule(
            $tag,
            $this->strings($related),
            $this->strings($this->value($settings, 'containerFor') ?? []),
            is_string($parent) ? $parent : ''
        );
    }

    /**
     * The 'value' of one setting, or null when the setting is missing.
     * Mixed: a bundle's settings are decoded from its JavaScript, so their
     * values are open.
     *
     * @param array<string,mixed> $settings
     */
    private function value(array $settings, string $key): mixed
    {
        $setting = $settings[ $key ] ?? null;

        return is_array($setting) ? ($setting['value'] ?? null) : null;
    }

    /**
     * The strings in a setting's value. Mixed: see value().
     *
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_map('strval', array_filter(is_array($values) ? $values : [], 'is_string')));
    }
}
