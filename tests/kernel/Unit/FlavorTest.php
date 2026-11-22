<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Flavor;
use WPMCP\Kernel\Ships;

final class FlavorTest extends TestCase
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function module(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'redirects',
            'tier' => 'free',
            'flavors' => [],
            'exclude_flavors' => [],
        ];
    }

    public function test_full_ships_every_module(): void
    {
        $full = Flavor::full();

        self::assertTrue($full->includes_module(self::module()));
        self::assertTrue($full->includes_module(self::module(['tier' => 'pro', 'exclude_flavors' => ['wporg']])));
    }

    public function test_full_honours_an_explicit_exclusion(): void
    {
        self::assertFalse(Flavor::full()->includes_module(self::module(['exclude_flavors' => ['full']])));
    }

    public function test_wporg_ships_free_modules_unless_excluded(): void
    {
        $wporg = new Flavor(id: 'wporg', main_file: 'wpmcp.php', text_domain: 'wpmcp', ships: Ships::Free);

        self::assertTrue($wporg->includes_module(self::module()));
        self::assertFalse($wporg->includes_module(self::module(['tier' => 'pro'])));
        self::assertFalse($wporg->includes_module(self::module(['exclude_flavors' => ['wporg']])));
    }

    public function test_a_vertical_flavor_ships_only_modules_that_name_it(): void
    {
        $woo = new Flavor(id: 'woocommerce', main_file: 'wpmcp-for-woocommerce.php', text_domain: 'wpmcp-for-woocommerce', ships: Ships::Selected);

        self::assertFalse($woo->includes_module(self::module()));
        self::assertTrue($woo->includes_module(self::module(['flavors' => ['woocommerce']])));
        self::assertFalse($woo->includes_module(self::module(['flavors' => ['woocommerce'], 'exclude_flavors' => ['woocommerce']])));
    }

    public function test_tier_overrides_apply_per_ability(): void
    {
        $elementor = new Flavor(
            id: 'elementor',
            main_file: 'safe-mcp-for-elementor.php',
            text_domain: 'safe-mcp-for-elementor',
            ships: Ships::Selected,
            lift_with_pro: true,
            tier_overrides: ['wpmcp/elementor-get-page' => 'free'],
        );

        self::assertSame('free', $elementor->tier_for('wpmcp/elementor-get-page', 'pro'));
        self::assertSame('pro', $elementor->tier_for('wpmcp/elementor-set-page', 'pro'));
        self::assertTrue($elementor->lift_with_pro);
    }

    public function test_an_ability_excluded_from_the_flavor_is_not_shipped(): void
    {
        $woo = new Flavor(id: 'woocommerce', main_file: 'x.php', text_domain: 'x', ships: Ships::Selected);

        self::assertFalse($woo->includes_ability(['exclude_flavors' => ['woocommerce']]));
        self::assertTrue($woo->includes_ability(['exclude_flavors' => ['wporg']]));
        self::assertTrue($woo->includes_ability([]));
    }

    public function test_round_trips_through_its_array_form(): void
    {
        $flavor = new Flavor(id: 'woocommerce', main_file: 'w.php', text_domain: 'w', ships: Ships::Selected, tier_overrides: ['a/b' => 'free']);

        self::assertEquals($flavor, Flavor::from_array($flavor->to_array()));
    }

    public function test_rejects_a_bad_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Flavor(id: 'Woo Commerce', main_file: 'x.php', text_domain: 'x', ships: Ships::All);
    }
}
