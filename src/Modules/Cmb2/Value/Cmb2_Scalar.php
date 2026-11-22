<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads of the loosely typed values CMB2 hands over and
 * an operation's JSON arguments carry (issue #1089). A scalar converts as a
 * PHP cast would; anything else reads as empty.
 */
final class Cmb2_Scalar
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

    private function __construct()
    {
    }
}
