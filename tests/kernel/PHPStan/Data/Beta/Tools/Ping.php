<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Beta\Tools;

final class Ping
{
    public static function now(): string
    {
        return 'pong';
    }
}
