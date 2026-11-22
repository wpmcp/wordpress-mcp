<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter;

use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Tools\List_Items;

// An adapter must not reach up into Tools.
final class Leaky_Adapter
{
    public function __construct(private readonly List_Items $tool)
    {
    }

    public function tool(): List_Items
    {
        return $this->tool;
    }
}
