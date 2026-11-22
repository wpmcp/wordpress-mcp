<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Ops;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hides secrets in ACF Extended settings before they are read out (issue
 * #1089): any value under a key named like a licence key, API key, token,
 * password or other credential, at any depth, and the value of a key/value
 * pair (a form action's custom setting, a field group's custom meta) whose
 * key is named like one.
 */
final class Acfe_Secret_Mask
{
    public const HIDDEN = '[redacted]';

    private const SECRET_WORDS = '/(secret|token|passw|pwd|api_?key|apikey|licen[cs]e|private_?key|credential|access_?key|purchase_?code|client_?secret)/';

    /** Mixed: any settings value, handed back in the same shape with secrets hidden. */
    public function mask(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $pair_key = $value['key'] ?? $value['name'] ?? null;
        $pair     = array_key_exists('value', $value) && is_string($pair_key) && $this->names_a_secret($pair_key);
        foreach ($value as $key => $item) {
            if (($pair && 'value' === $key) || (is_string($key) && $this->names_a_secret($key))) {
                $value[ $key ] = (null === $item || '' === $item || [] === $item || is_bool($item)) ? $item : self::HIDDEN;
                continue;
            }
            $value[ $key ] = $this->mask($item);
        }
        return $value;
    }

    public function names_a_secret(string $name): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        $norm   = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced));
        return 1 === preg_match(self::SECRET_WORDS, $norm);
    }
}
