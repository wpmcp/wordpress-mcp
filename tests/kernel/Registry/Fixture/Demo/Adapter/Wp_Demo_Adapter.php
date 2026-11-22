<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter;

use WPMCP\Api\V1\Attribute\Provides;

#[Provides(Demo_Adapter::class)]
final class Wp_Demo_Adapter implements Demo_Adapter
{
    /** @return list<array<string, mixed>> */
    public function things(int $limit): array
    {
        return array_slice([['id' => 1], ['id' => 2]], 0, $limit);
    }
}
