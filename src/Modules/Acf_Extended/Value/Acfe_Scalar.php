<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads of the loosely typed values ACF Extended hands over and
 * an operation's JSON arguments carry (issue #1089). A scalar converts as a
 * PHP cast would; anything else reads as empty.
 */
final class Acfe_Scalar
{
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_map(static fn (mixed $item): string => self::text($item), (array) $value));
    }

    /**
     * An array with its string keys kept, as a settings or config array.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $key => $item) {
            $out[ (string) $key ] = $item;
        }
        return $out;
    }

    /** Whether a key is a string that starts with $prefix (an array key may be an int at runtime). */
    public static function starts_with(mixed $key, string $prefix): bool
    {
        return is_string($key) && str_starts_with($key, $prefix);
    }

    private function __construct()
    {
    }
}
