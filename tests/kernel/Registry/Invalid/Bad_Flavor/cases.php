<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Bad_Flavor;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'bad-flavor', order: 1, flavors: ['nosuchflavor'])]
final class Module_Class
{
}
