<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Tools;

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Contract\Ability_Handler;
use WPMCP\Api\V1\Value\Op;
use WPMCP\Api\V1\Value\Tier;

#[Ability(
    name: 'wpmcp/update-thing',
    capability: 'manage_options',
    domain: 'demo',
    operation: Op::Update,
    position: 10,
    tier: Tier::Pro,
    destructive: true,
    objects: 'objects',
    input_factory: 'input',
    description_factory: 'description',
    exclude_flavors: ['wporg'],
)]
#[Ability(name: 'wpmcp/delete-thing', description: 'Delete a demo thing', operation: Op::Delete, method: 'delete', capability: 'manage_options', domain: 'demo')]
final class Update_Thing implements Ability_Handler
{
    /** @return array<string, mixed> */
    public static function input(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public static function description(): string
    {
        return sprintf('Update a demo thing (%d fields)', 1);
    }

    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return true;
    }

    /** @param array<string, mixed> $input */
    public function delete(array $input): mixed
    {
        return true;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function objects(array $input): array
    {
        return [];
    }
}
