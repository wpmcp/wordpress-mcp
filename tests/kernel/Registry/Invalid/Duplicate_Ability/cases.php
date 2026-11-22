<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Duplicate_Ability;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'dup-ability', order: 1)]
final class Module_Class
{
}

#[Ability(name: 'wpmcp/same', description: 'One')]
final class One
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return null;
    }
}

#[Ability(name: 'wpmcp/same', description: 'Two')]
final class Two
{
    /** @param array<string, mixed> $input */
    public function handle(array $input): mixed
    {
        return null;
    }
}
