<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Beta\Value;

final class Token
{
    public function __construct(public readonly string $value)
    {
    }

    public static function make(string $value): self
    {
        return new self($value);
    }
}
