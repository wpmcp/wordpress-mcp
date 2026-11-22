<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Value;

final class Item
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
    ) {
    }

    public static function of(int $id, string $title): self
    {
        return new self($id, $title);
    }

    public function with_title(string $title): static
    {
        return new static($this->id, $title);
    }
}
