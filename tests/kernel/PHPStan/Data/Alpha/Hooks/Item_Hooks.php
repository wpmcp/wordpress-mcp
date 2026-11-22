<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Hooks;

use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter\Alpha_Adapter;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Ops\Item_Ops;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Tools\List_Items;

// Hooks may delegate to Tools, Ops and the adapter interface.
final class Item_Hooks
{
    public function __construct(
        private readonly List_Items $tool,
        private readonly Item_Ops $ops,
        private readonly Alpha_Adapter $adapter,
    ) {
    }

    public function on_save(): void
    {
        $this->tool->handle([]);
        $this->ops->count();
        $this->adapter->items(1);
    }
}
