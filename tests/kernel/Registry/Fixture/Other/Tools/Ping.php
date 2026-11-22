<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Other\Tools;

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Contract\Ability_Handler;

#[Ability(name: 'wpmcp/ping', description: 'Ping')]
#[Ability(name: 'wpmcp/aaa-unpositioned', description: 'Sorts by name after positioned ones', method: 'handle')]
final class Ping implements Ability_Handler
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return 'pong';
    }
}
