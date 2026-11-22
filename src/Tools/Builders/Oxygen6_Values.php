<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads of the loosely typed values the Oxygen 6 writes of issue #805
 * handle: decoded JSON (trees decode to objects, stored rows and call
 * arguments to arrays) and Oxygen's own registry. Each read returns the
 * expected type, converting a scalar the way a PHP cast does and falling
 * back to an empty value for anything else. Pure functions, no state.
 */
final class Oxygen6_Values
{
    private function __construct()
    {
    }

    /**
     * The value at $keys inside nested arrays and objects, or null where the
     * path stops (a missing key, or a value that is neither).
     *
     * @param mixed $value decoded JSON, an argument array or a registry object
     */
    public static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $fields = is_object($value) ? get_object_vars($value) : $value;
            if (! is_array($fields) || ! array_key_exists($key, $fields)) {
                return null;
            }
            $value = $fields[ $key ];
        }

        return $value;
    }

    /** A scalar as (string) casts it, '' for anything else. */
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A scalar as (int) casts it, 0 for anything else. */
    public static function int(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * An array or an object's fields as a map, [] for anything else.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $fields = is_object($value) ? get_object_vars($value) : $value;
        if (! is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $key => $item) {
            $out[ (string) $key ] = $item;
        }

        return $out;
    }

    /**
     * The value as (array) casts it: an array as it is, an object's fields,
     * [] for null and a one-item list for a scalar.
     *
     * @return array<mixed>
     */
    public static function items(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return null === $value ? [] : [$value];
    }

    /**
     * The child nodes of a tree node, in order.
     *
     * @return list<object>
     */
    public static function children(object $node): array
    {
        return array_values(array_filter(self::items(self::at($node, 'children')), 'is_object'));
    }
}
