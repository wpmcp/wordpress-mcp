<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads scalars and lists out of what a gate plugin stored or a call passed
 * (issue #1099). Pure functions: a scalar becomes its string or int, any
 * other value the empty one, so a damaged option reads as unset instead of
 * as "Array".
 */
final class Stored
{
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /** @return array<mixed> */
    public static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> every item as a string, in order. */
    public static function strings(mixed $value): array
    {
        return array_values(array_map([self::class, 'text'], (array) $value));
    }

    /** @return list<int> every item as an int, in order. */
    public static function ints(mixed $value): array
    {
        return array_values(array_map([self::class, 'int'], (array) $value));
    }

    /** The named property of a stdClass node, or null. */
    public static function prop(mixed $node, string $name): mixed
    {
        return $node instanceof \stdClass && property_exists($node, $name) ? $node->{$name} : null;
    }
}
