<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * The Pro half of issue #60 ("read free / writes pro"): get-global-settings
 * is the free read (tests/free/Elementor/GlobalSettingsReadTest), and every
 * kit write stays on the pro tier.
 */
class GlobalSettingsReadProTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    public function test_writes_stay_on_the_pro_tier(): void
    {
        $abilities = RegisteredAbilities::manifest_map();

        foreach (
            [
                'wpmcp/update-global-colors',
                'wpmcp/update-global-typography',
                'wpmcp/replace-system-colors',
                'wpmcp/replace-system-typography',
            ] as $name
        ) {
            $this->assertSame('pro', $abilities[$name] ?? null, "{$name} must stay pro tier");
        }
    }
}
