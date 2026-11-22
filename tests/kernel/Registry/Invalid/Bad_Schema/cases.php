<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Bad_Schema;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'bad-schema', order: 1)]
final class Module_Class
{
}

#[Ability(name: 'wpmcp/bad-schema', description: 'Bad', input: ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']])]
final class Tool
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return null;
    }
}
