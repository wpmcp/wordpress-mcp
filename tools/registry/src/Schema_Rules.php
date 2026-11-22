<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * The input schema rules a compiled ability must meet: the subset
 * WPMCP\MCP\Wire_Schema normalizes and the MCP wire accepts. The root is an
 * object; properties is a map of schemas; required names only declared
 * properties; every type is a JSON Schema type; nested schemas follow the
 * same rules.
 */
final class Schema_Rules
{
    private const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];
    private const MAPS = ['properties', 'patternProperties', 'definitions', '$defs'];
    private const LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];
    private const SINGLES = ['items', 'additionalProperties', 'not', 'if', 'then', 'else'];

    /**
     * @param array<string, mixed> $schema
     * @return list<string> problems, empty when valid
     */
    public static function check(array $schema, string $where): array
    {
        $problems = [];
        if ('object' !== ($schema['type'] ?? null)) {
            $problems[] = sprintf('%s input must be a schema of type object.', $where);
        }
        self::walk($schema, $where . ' input', $problems);
        return $problems;
    }

    /**
     * @param array<mixed>  $schema
     * @param list<string> $problems
     */
    private static function walk(array $schema, string $path, array &$problems): void
    {
        if (isset($schema['type'])) {
            foreach ((array) $schema['type'] as $type) {
                if (! in_array($type, self::TYPES, true)) {
                    $problems[] = sprintf('%s has unknown type %s.', $path, var_export($type, true));
                }
            }
        }
        foreach (self::MAPS as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }
            if (! is_array($schema[ $key ]) || ([] !== $schema[ $key ] && array_is_list($schema[ $key ]))) {
                $problems[] = sprintf('%s %s must be a map of schemas.', $path, $key);
                continue;
            }
            foreach ($schema[ $key ] as $name => $child) {
                if (! is_array($child)) {
                    $problems[] = sprintf('%s.%s is not a schema.', $path, (string) $name);
                    continue;
                }
                self::walk($child, $path . '.' . $name, $problems);
            }
        }
        foreach (self::LISTS as $key) {
            if (array_key_exists($key, $schema)) {
                if (! is_array($schema[ $key ]) || ! array_is_list($schema[ $key ])) {
                    $problems[] = sprintf('%s %s must be a list of schemas.', $path, $key);
                    continue;
                }
                foreach ($schema[ $key ] as $i => $child) {
                    if (is_array($child)) {
                        self::walk($child, "{$path}.{$key}[{$i}]", $problems);
                    } else {
                        $problems[] = sprintf('%s.%s[%d] is not a schema.', $path, $key, $i);
                    }
                }
            }
        }
        foreach (self::SINGLES as $key) {
            if (isset($schema[ $key ]) && is_array($schema[ $key ])) {
                self::walk($schema[ $key ], $path . '.' . $key, $problems);
            }
        }
        if (array_key_exists('required', $schema)) {
            if (! is_array($schema['required']) || ! array_is_list($schema['required'])) {
                $problems[] = sprintf('%s required must be a list of property names.', $path);
                return;
            }
            $declared = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($schema['required'] as $name) {
                if (! is_string($name) || ! array_key_exists($name, $declared)) {
                    $problems[] = sprintf('%s requires %s, which is not in properties.', $path, is_string($name) ? $name : var_export($name, true));
                }
            }
        }
    }
}
