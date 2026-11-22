<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * Writes plain PHP literals (short arrays, scalars, null) so a compiled
 * registry file is a `return [...]` opcache can keep as an immutable array.
 * Objects and closures are refused: nothing executable reaches the output.
 */
final class Php_Exporter
{
    public static function file(mixed $value, string $header): string
    {
        return "<?php\n// " . $header . "\n\ndeclare(strict_types=1);\n\nreturn " . self::export($value) . ";\n";
    }

    public static function export(mixed $value, int $depth = 0): string
    {
        if (is_array($value)) {
            if ([] === $value) {
                return '[]';
            }
            $pad = str_repeat('    ', $depth + 1);
            $list = array_is_list($value);
            $lines = [];
            foreach ($value as $key => $item) {
                $lines[] = $pad . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $depth + 1) . ',';
            }
            return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
        }
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_string($value)) {
            return var_export($value, true);
        }
        if (is_float($value)) {
            $out = var_export($value, true);
            return str_contains($out, '.') || str_contains($out, 'E') || str_contains($out, 'N') ? $out : $out . '.0';
        }
        throw new \InvalidArgumentException('Only arrays and scalars can be exported, got ' . get_debug_type($value) . '.');
    }
}
