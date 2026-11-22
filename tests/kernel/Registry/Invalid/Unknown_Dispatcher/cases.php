<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Unknown_Dispatcher;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'unknown-dispatcher', order: 1)]
final class Module_Class
{
}

#[Operations(dispatcher: 'no-such-pair', host: 'A')]
final class A
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [];
    }
}
