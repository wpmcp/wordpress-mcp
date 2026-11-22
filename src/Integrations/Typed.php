<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads of the loosely typed values a host plugin hands back (rows,
 * option arrays, objects from its API) and of an op's JSON arguments: each
 * returns the expected type or the default. Pure functions, no state.
 */
final class Typed
{
    private function __construct()
    {
    }

    public static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && is_numeric($value) ? (int) $value : (is_float($value) || is_bool($value) ? (int) $value : $default);
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $default;
    }

    /** True for 1, '1', true, 'yes', 'on' and 'true'. */
    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(is_string($value) ? strtolower($value) : $value, [ 1, '1', 'yes', 'on', 'true' ], true);
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[ (string) $key ] = $item;
        }
        return $out;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        $out = [];
        foreach (self::list($value) as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /** @return list<int> */
    public static function ints(mixed $value): array
    {
        $out = [];
        foreach (self::list($value) as $item) {
            $out[] = self::int($item);
        }
        return $out;
    }

    /** One field of an array or an object, or null. */
    public static function field(mixed $from, string $key): mixed
    {
        return self::map($from)[ $key ] ?? null;
    }

    /** The current site's \wpdb, or null outside WordPress. */
    public static function db(): ?\wpdb
    {
        $db = $GLOBALS['wpdb'] ?? null;
        return $db instanceof \wpdb ? $db : null;
    }
}
