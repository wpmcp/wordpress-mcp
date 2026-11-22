<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Invalid\Refusal_Facts;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Attribute\Ability;
use WPMCP\Api\V1\Attribute\Module;
use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Value\Requires;

#[Module(id: 'refusal-facts', order: 1, requires: new Requires(plugin: ['x/x.php'], name: 'X Plugin'))]
final class Module_Class
{
}
