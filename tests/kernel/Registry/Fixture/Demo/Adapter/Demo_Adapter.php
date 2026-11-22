<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter;

interface Demo_Adapter
{
    /** @return list<array<string, mixed>> */
    public function things(int $limit): array;
}
