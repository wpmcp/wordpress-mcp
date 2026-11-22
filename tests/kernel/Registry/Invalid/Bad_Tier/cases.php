<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Bad_Tier;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'bad-tier', order: 1)]
final class Module_Class
{
}

#[Ability(name: 'wpmcp/bad-tier', description: 'Bad tier', tier: 'gold')]
final class Tool
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return null;
    }
}
