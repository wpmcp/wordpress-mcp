<?php

declare(strict_types=1);

namespace WPMCP\Registry;

use WPMCP\Kernel\Flavor;
use WPMCP\Kernel\Ships;

/**
 * The flavor definitions the generator knows. Phase 1 keeps them here; the
 * design's flavors/<id>.php files replace this when the release builds
 * read the registry.
 */
final class Flavors
{
    /** @return array<string, Flavor> */
    public static function all(): array
    {
        return [
            'full' => Flavor::full(),
            'wporg' => new Flavor(id: 'wporg', main_file: 'wpmcp.php', text_domain: 'wpmcp', ships: Ships::Free),
            'woocommerce' => new Flavor(id: 'woocommerce', main_file: 'wpmcp-for-woocommerce.php', text_domain: 'wpmcp-for-woocommerce', ships: Ships::Selected),
            'elementor' => new Flavor(id: 'elementor', main_file: 'safe-mcp-for-elementor.php', text_domain: 'safe-mcp-for-elementor', ships: Ships::Selected, lift_with_pro: true),
        ];
    }

    public static function get(string $id): ?Flavor
    {
        return self::all()[ $id ] ?? null;
    }
}
