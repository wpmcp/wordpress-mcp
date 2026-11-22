<?php

declare(strict_types=1);

namespace WPMCP\Modules\Cmb2\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which CMB2 values are secrets (issue #1089): a field rendered as a
 * password input, and a field whose id or label names a licence key, API
 * key, token, password or other credential. A secret is never read out and
 * never written.
 */
final class Cmb2_Secret_Policy
{
    /** What a hidden secret reads as. */
    public const HIDDEN = '[redacted]';

    /** Words in a field id or label that mark its value as a secret. */
    private const SECRET_WORDS = '/(secret|token|passw|pwd|api_?key|apikey|licen[cs]e|private_?key|credential|access_?key|purchase_?code|client_?secret)/';

    /** @param array<string, mixed> $config a field's config array. */
    public function is_secret(array $config): bool
    {
        $attributes = is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        if ('password' === ($attributes['type'] ?? '')) {
            return true;
        }
        foreach ([ $config['id'] ?? '', $config['name'] ?? '' ] as $name) {
            if (is_string($name) && $this->names_a_secret($name)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a name (a field id, label or array key) names a secret. */
    public function names_a_secret(string $name): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        $norm   = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced));
        return 1 === preg_match(self::SECRET_WORDS, $norm);
    }

    /**
     * A stored value as it may be read out: a secret field hidden, and in a
     * group's rows every sub field that is a secret or is named like one.
     * Mixed: whatever CMB2 stored, handed back in the same shape.
     */
    public function mask(Cmb2_Field_Spec $field, mixed $value): mixed
    {
        if ($field->secret) {
            return (null === $value || '' === $value || [] === $value) ? $value : self::HIDDEN;
        }
        if (! $field->is_group() || ! is_array($value)) {
            return $value;
        }
        foreach ($value as $index => $row) {
            if (is_array($row)) {
                $value[ $index ] = $this->mask_row($field, $row);
            }
        }
        return $value;
    }

    /**
     * One group row with its secret sub fields hidden.
     *
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private function mask_row(Cmb2_Field_Spec $field, array $row): array
    {
        foreach ($row as $key => $item) {
            $sub = $field->sub_fields[ (string) $key ] ?? null;
            if ((null !== $sub && $sub->secret) || (null === $sub && $this->names_a_secret((string) $key))) {
                $row[ $key ] = ('' === $item || null === $item) ? $item : self::HIDDEN;
            }
        }
        return $row;
    }
}
