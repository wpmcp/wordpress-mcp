<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Kernel;

use WPMCP\Api\V1\Value\Op;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Tools\List_Items;
use WPMCP\Tests\Kernel\PHPStan\Data\Legacy\Beta_Owned;

// Stands in for src/Kernel: it may name the API, never a module.
final class Thing
{
    public function op(): Op
    {
        return Op::Read;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return [List_Items::class, Beta_Owned::class];
    }
}
