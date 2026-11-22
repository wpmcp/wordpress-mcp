<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Legacy;

// A WPMCP class no module owns, standing in for legacy code (Plugin, a pack).
final class Service
{
    public static function instance(): self
    {
        return new self();
    }

    /** @return array<string, mixed> */
    public static function operations(): array
    {
        return [];
    }
}
