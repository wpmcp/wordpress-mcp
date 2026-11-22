<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Why a site gate settings write will not run (issue #1099). Decided before
 * any snapshot is taken, so a refused call writes nothing and burns no undo
 * point. Its message and data never carry a bypass link or an IP address.
 */
final class Refusal
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }

    /** @return array{code: string, message: string, data: array<string, mixed>} the dispatcher's validate() shape. */
    public function to_array(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'data' => $this->data];
    }

    /** @param list<string> $supported */
    public static function unsupported(string $label, string $field, array $supported): self
    {
        return new self(
            'field_unsupported',
            sprintf('%1$s has no %2$s setting this op can edit. It can edit: %3$s.', $label, $field, [] === $supported ? 'nothing' : implode(', ', $supported)),
            ['field' => $field, 'supported' => $supported],
        );
    }
}
