<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Forms_Integration;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tests\Support\Edition_Tree;

require_once __DIR__ . '/../../support/forms-adapters.php';

/**
 * Most dispatcher pairs register unconditionally (availability is a call-time
 * concern for them: the ability must exist to report "unavailable" cleanly),
 * so both halves must be present in the live abilities registry regardless of
 * whether the host plugin is active.
 *
 * The forms adapters are the exception (issue #66): each registers only while
 * its host plugin is loaded, so they are asserted separately below.
 */
class IntegrationAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/acf-read',
        'wpmcp/acf-write',
        'wpmcp/gravitytables-read',
        'wpmcp/gravitytables-write',
        'wpmcp/mec-read',
        'wpmcp/mec-write',
        'wpmcp/tec-read',
        'wpmcp/tec-write',
        'wpmcp/give-read',
        'wpmcp/give-write',
        'wpmcp/pmpro-read',
        'wpmcp/pmpro-write',
        'wpmcp/theme-read',
        'wpmcp/theme-write',
    ];

    public function test_dispatcher_pair_is_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_dispatcher_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    /**
     * Issue #66: "each adapter registers only when its plugin is active". The
     * real registration path is replayed with each adapter's availability
     * forced both ways through the same filter a site would use, so the
     * assertion is about Plugin's registration logic, not about which harness
     * doubles happen to be loaded.
     */
    public function test_each_forms_adapter_registers_only_while_its_host_plugin_is_loaded(): void
    {
        foreach (self::forms_adapters() as $slug => $class) {
            $this->assertTrue((new $class())->registers_only_when_available(), "{$slug} must follow its host plugin");
        }

        $absent = self::replayed_names(false);
        foreach (self::forms_pair_names() as $name) {
            $this->assertNotContains($name, $absent, "{$name} must not register while its plugin is absent");
        }
        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $absent, "{$name} is not a forms pair and must still register unconditionally");
        }

        $present = self::replayed_names(true);
        foreach (self::forms_pair_names() as $name) {
            $this->assertContains($name, $present, "{$name} must register while its plugin is loaded");
        }
    }

    public function test_forms_adapter_should_register_follows_availability(): void
    {
        $absent = new class extends Forms_Integration {
            public bool $available = false;

            public function integration(): string
            {
                return 'fixtureforms';
            }

            public function is_available(): bool
            {
                return $this->available;
            }

            protected function operations(): array
            {
                return [];
            }
        };

        $this->assertFalse($absent->should_register());
        $absent->available = true;
        $this->assertTrue($absent->should_register());
    }

    /**
     * The forms adapters this tree ships: all of them with the Pro code, and
     * without it only those whose class lives at a public path (the adapter
     * pack is Pro and absent from the free build).
     *
     * @return array<string, class-string<Forms_Integration>> slug => class
     */
    private static function forms_adapters(): array
    {
        if (Edition_Tree::has_pro_code()) {
            return wpmcp_forms_adapter_classes();
        }
        return array_filter(
            wpmcp_forms_adapter_classes(),
            static fn (string $class): bool => ! Edition_Tree::is_pro_path('src/Integrations/' . substr(strrchr($class, '\\'), 1) . '.php')
        );
    }

    /** @return string[] the wpmcp/{slug}-read and -write names of forms_adapters(). */
    private static function forms_pair_names(): array
    {
        $names = [];
        foreach (array_keys(self::forms_adapters()) as $slug) {
            $names[] = "wpmcp/{$slug}-read";
            $names[] = "wpmcp/{$slug}-write";
        }
        return $names;
    }

    /**
     * Ability names the real registration path produces with every forms
     * adapter's availability forced to $available.
     *
     * @return string[]
     */
    private static function replayed_names(bool $available): array
    {
        $forms = array_keys(wpmcp_forms_adapter_classes());
        $force = static function (bool $register, string $slug) use ($forms, $available): bool {
            return in_array($slug, $forms, true) ? $available : $register;
        };

        // RegisteredAbilities forces every integration on at PHP_INT_MAX - 1
        // for the manifest; this runs after it so the forms pairs follow
        // $available.
        add_filter('wpmcp_integration_should_register', $force, PHP_INT_MAX, 2);
        try {
            return array_map(static fn ($a) => $a->name, self::enumerate());
        } finally {
            remove_filter('wpmcp_integration_should_register', $force, PHP_INT_MAX);
        }
    }

    /** @return \WPMCP\MCP\Ability[] */
    private static function enumerate(): array
    {
        return RegisteredAbilities::all();
    }
}
