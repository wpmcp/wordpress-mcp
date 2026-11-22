<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter;

use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Value\Item;

interface Alpha_Adapter
{
    /** @return list<Item> */
    public function items(int $limit): array;
}
