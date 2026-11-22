<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Oxygen 6 Component properties (issue #805), as the Oxygen 6.1 source
 * stores and renders them. A Component declares editable properties on its
 * nodes; an instance (an OxygenElements\Component node) keeps, in
 * content.content.block, the values by property key (`properties`) and the
 * `targets` list {nodeId, controlPath, propertyKey} the renderer walks:
 * replaceNodePropertiesWithEditedPropertiesFromComponent() assigns each set
 * value at its control path on the Component node with that id, when the
 * property is enabled. The editor rebuilds targets from every declared
 * property, so a write does the same.
 *
 * Pure document work: no storage, no WordPress calls.
 */
final class Oxygen6_Component_Properties
{
    /**
     * Every property the Component declares, in tree order (root first).
     *
     * @return Oxygen6_Component_Property[]
     */
    public function declared(object $component): array
    {
        $root = Oxygen6_Values::at($component, 'root');
        $out  = [];
        if (is_object($root)) {
            $this->collect($root, $out);
        }

        return $out;
    }

    /** The Component post id an instance node names, or 0. */
    public function component_id(object $node): int
    {
        if (Oxygen_Page::COMPONENT_TYPE !== Oxygen6_Values::at($node, 'data', 'type')) {
            return 0;
        }
        $id = Oxygen6_Values::at($node, 'data', 'properties', 'content', 'content', 'block', 'componentId');

        return is_int($id) ? $id : 0;
    }

    /**
     * Set values on the instance at $path: a null removes a value, anything
     * else replaces it. Only enabled declared properties may be set.
     *
     * @param array<string,mixed> $values property key => value
     * @return array{0:object,1:array<string,mixed>} new document, and the instance's values after the write
     */
    public function apply(object $doc, string $path, array $values, object $component): array
    {
        if ([] === $values) {
            Oxygen6_Request_Error::raise('set_properties needs attrs: {propertyKey: value} (null removes a value).');
        }
        $node = Breakdance_Tree::node($doc, $path);
        if (0 === $this->component_id($node)) {
            Oxygen6_Request_Error::raise("Node {$path} is not a Component instance (an OxygenElements\\Component node).");
        }

        $declared = $this->declared($component);
        $enabled  = [];
        $disabled = [];
        foreach ($declared as $property) {
            if ($property->enabled) {
                $enabled[ $property->key ] = true;
            } else {
                $disabled[ $property->key ] = true;
            }
        }
        foreach (array_keys($values) as $key) {
            if (isset($disabled[ $key ]) && ! isset($enabled[ $key ])) {
                Oxygen6_Request_Error::raise("Property '{$key}' is disabled in the Component, so Oxygen ignores its value; enable it in the Component first.");
            }
            if (! isset($enabled[ $key ])) {
                Oxygen6_Request_Error::raise("Unknown property '{$key}'; this Component's properties are " . ([] === $enabled ? '(none)' : implode(', ', array_keys($enabled))) . ' (read get-builder-content scope properties).');
            }
        }

        $merged = Oxygen6_Values::map(Oxygen6_Values::at($node, 'data', 'properties', 'content', 'content', 'block', 'properties'));
        foreach ($values as $key => $value) {
            if (null === $value) {
                unset($merged[ $key ]);
                continue;
            }
            $merged[ $key ] = $value;
        }
        $targets = array_map(static fn (Oxygen6_Component_Property $p): array => $p->target(), $declared);

        // Cleared first, so a value object replaces the old one instead of
        // merging into it, and an emptied map stays an object.
        $doc = Breakdance_Tree::update($doc, $path, ['content' => ['content' => ['block' => ['targets' => null, 'properties' => null]]]]);
        $doc = Breakdance_Tree::update($doc, $path, ['content' => ['content' => ['block' => ['targets' => $targets, 'properties' => (object) $merged]]]]);

        return [$doc, $merged];
    }

    /** @param Oxygen6_Component_Property[] $out */
    private function collect(object $node, array &$out): void
    {
        $list = Oxygen6_Values::at($node, 'data', 'properties', 'meta', 'component', 'editableProperties');
        foreach (is_array($list) ? $list : [] as $item) {
            $key  = is_object($item) ? ($item->propertyKey ?? null) : null;
            $path = is_object($item) ? ($item->controlPath ?? null) : null;
            if (! is_string($key) || '' === $key || ! is_string($path) || '' === $path) {
                continue;
            }
            $out[] = new Oxygen6_Component_Property(
                $key,
                is_string($item->label ?? null) ? $item->label : '',
                $path,
                Oxygen6_Values::int(Oxygen6_Values::at($node, 'id')),
                isset($item->enabled) ? (bool) $item->enabled : true
            );
        }
        foreach (Oxygen6_Values::children($node) as $child) {
            $this->collect($child, $out);
        }
    }
}
