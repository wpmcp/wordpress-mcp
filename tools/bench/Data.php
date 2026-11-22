<?php

declare(strict_types=1);

namespace WPMCP\Bench;

/**
 * Typed reads of decoded tool results and fixtures (issue #1130).
 *
 * Tool results come back as JSON-shaped arrays of mixed values. Every read
 * goes through here, so a scenario says what type it expects and a value of
 * another type reads as empty (0, '', []) instead of being cast blindly. A
 * path walks nested arrays: Data::int($state, 'orders', 'body', 0, 'id').
 *
 * Pure functions only, no state. REPO-ONLY, like everything under tools/.
 */
final class Data
{
    /** The value at $path, or null when any step of it is missing. */
    public static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[ $key ];
        }
        return $value;
    }

    /** @return array<mixed> The array at $path, or [] when it is not one. */
    public static function arr(mixed $value, int|string ...$path): array
    {
        $found = self::at($value, ...$path);
        return is_array($found) ? $found : [];
    }

    /** @return list<array<mixed>> The arrays in the list at $path, in order; other entries are skipped. */
    public static function rows(mixed $value, int|string ...$path): array
    {
        return array_values(array_filter(self::arr($value, ...$path), 'is_array'));
    }

    /** A string or number at $path as a string, else ''. */
    public static function str(mixed $value, int|string ...$path): string
    {
        $found = self::at($value, ...$path);
        return is_string($found) || is_int($found) || is_float($found) ? (string) $found : '';
    }

    /** An int, or an integer string, at $path, else 0. */
    public static function int(mixed $value, int|string ...$path): int
    {
        $found = self::at($value, ...$path);
        if (is_int($found)) {
            return $found;
        }
        return is_string($found) && 1 === preg_match('/^-?\d+$/', $found) ? (int) $found : 0;
    }

    /** A number, or a numeric string, at $path, else 0.0. */
    public static function float(mixed $value, int|string ...$path): float
    {
        $found = self::at($value, ...$path);
        return is_int($found) || is_float($found) || (is_string($found) && is_numeric($found)) ? (float) $found : 0.0;
    }

    /** True only for a value at $path that is true, a non-zero number or a non-empty string other than '0'. */
    public static function truthy(mixed $value, int|string ...$path): bool
    {
        $found = self::at($value, ...$path);
        return true === $found || (is_int($found) && 0 !== $found) || (is_string($found) && '' !== $found && '0' !== $found);
    }

    /** @return list<int> The ints (or integer strings) in the list at $path. */
    public static function ints(mixed $value, int|string ...$path): array
    {
        $out = [];
        foreach (self::arr($value, ...$path) as $item) {
            $id = self::int([$item], 0);
            if (0 !== $id) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return list<string> The strings in the list at $path. */
    public static function strs(mixed $value, int|string ...$path): array
    {
        return array_values(array_filter(self::arr($value, ...$path), 'is_string'));
    }

    /** @return array<string, mixed> The array at $path keyed by string, or [] when it is not one. */
    public static function map(mixed $value, int|string ...$path): array
    {
        $out = [];
        foreach (self::arr($value, ...$path) as $key => $item) {
            $out[ (string) $key ] = $item;
        }
        return $out;
    }

    /** The JSON of $value, '' when it cannot be encoded. */
    public static function json(mixed $value, int $flags = 0): string
    {
        $json = json_encode($value, $flags);
        return false === $json ? '' : $json;
    }
}
