<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Tools;

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Contract\Ability_Handler;
use WPMCP\Api\V1\Value\Op;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter\Demo_Adapter;

#[Ability(
    name: 'wpmcp/list-things',
    description: 'List demo things',
    input: self::INPUT,
    capability: 'manage_options',
    domain: 'demo',
    operation: Op::Read,
    position: 20,
)]
final class List_Things implements Ability_Handler
{
    public const INPUT = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1]]];

    public function __construct(private readonly Demo_Adapter $things)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return ['things' => $this->things->things((int) ($input['limit'] ?? 10))];
    }
}
