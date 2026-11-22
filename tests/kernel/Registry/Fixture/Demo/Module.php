<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo;

use WPMCP\Api\V1\Attribute\Feature;
use WPMCP\Api\V1\Attribute\Module as Module_Attribute;
use WPMCP\Api\V1\Attribute\Owns_Data;
use WPMCP\Api\V1\Value\Requires;

#[Module_Attribute(
    id: 'demo',
    group: 'demo',
    order: 200,
    flavors: ['woocommerce'],
    requires: new Requires(plugin: ['demo/demo.php'], name: 'Demo Plugin', slug: 'demo'),
    test_plugins: ['demo/demo.php'],
)]
#[Owns_Data(options: ['wpmcp_demo'], tables: ['wpmcp_demo_rows'], cron: ['wpmcp_demo_cron'])]
#[Feature('Demo things: list and update them safely')]
final class Module
{
}
