<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Beta;

use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Contract\Has_Boot;
use WPMCP\Api\V1\Value\Boot_Context;

function beta_helper(): void
{
}

#[Module(id: 'beta')]
final class Beta_Module implements Has_Boot
{
    use \WPMCP\Tests\Kernel\PHPStan\Data\Beta\Value\Token_Trait;

    private int $count = 0;

    public function boot(Boot_Context $context): void
    {
    }

    public function register(): void
    {
        $this->count++;
    }
}
