<?php

declare(strict_types=1);

namespace WPMCP\Modules\Acf_Extended\Value;

use WPMCP\Integrations\Support\Handler_Refusal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Why an ACF Extended read cannot run (issue #1089): the ['code',
 * 'message', 'data'] shape the dispatcher's validate step returns, or the
 * exception a handler throws.
 */
final class Acfe_Refusal
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = []
    ) {
    }

    /** @return array{code: string, message: string, data: array<string, mixed>} */
    public function to_array(): array
    {
        return [ 'code' => $this->code, 'message' => $this->message, 'data' => $this->data ];
    }

    public function to_exception(): Handler_Refusal
    {
        return new Handler_Refusal($this->code, $this->message, $this->data);
    }
}
