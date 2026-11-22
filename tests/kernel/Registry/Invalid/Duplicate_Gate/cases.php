<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Duplicate_Gate;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'dup-gate', order: 1)]
final class Module_Class
{
}

#[Opt_In_Gate(filter: 'wpmcp_same_gate', labels: 'labels')]
final class A
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['wpmcp_same_gate' => 'A'];
    }
}

#[Opt_In_Gate(filter: 'wpmcp_same_gate', labels: 'labels')]
final class B
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['wpmcp_same_gate' => 'B'];
    }
}
