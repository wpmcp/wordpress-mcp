<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Other;

use WPMCP\Api\V1\Attribute\Module;

#[Module(id: 'other', group: 'other', order: 100, exclude_flavors: ['woocommerce'])]
final class Other_Module
{
}
