<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Ops;

use WPMCP\Api\V1\Contract\Operation_Provider;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter\Alpha_Adapter;

final class Item_Ops implements Operation_Provider
{
    public function __construct(private readonly Alpha_Adapter $adapter)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [];
    }

    public function count(): int
    {
        return count($this->adapter->items(100)) + count(static::operations());
    }
}
