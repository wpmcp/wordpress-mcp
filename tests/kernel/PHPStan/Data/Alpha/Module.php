<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha;

use WPMCP\Api\V1\Attribute\Module as Module_Declaration;
use WPMCP\Api\V1\Value\Requires;

if (! defined('ABSPATH')) {
    exit;
}

#[Module_Declaration(id: 'alpha', requires: new Requires(plugin: ['acme/acme.php']), availability: 'available')]
final class Module
{
    public const SLUG = 'alpha';

    public static function available(): bool
    {
        return true;
    }
}
