<?php

namespace WPMCP\Tests\Pro\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Pro half of the SEO domain:
 * generate-schema-markup, generate-meta-tags, get-social-meta,
 * set-social-image and get-term-seo-meta require edit_posts;
 * update-term-seo-meta requires manage_categories, like the other term
 * writes. The two generators are always registered; the plugin-backed tools
 * are gated behind an active SEO plugin, matching
 * SeoAbilitiesRegistrationTest's guard.
 *
 * The free half lives in tests/free/Capabilities/SeoCapabilityTest. What the
 * pair defends is that no future edit to register_seo_abilities() can
 * silently widen one ability's capability while the others stay correct.
 * Tier is a separate concern and stays covered by the per-tool tests.
 */
class SeoCapabilityProTest extends \WP_UnitTestCase
{
    /**
     * The declared capability of every Pro SEO ability. Asserted against
     * declared() rather than all(), because all() drops the pro entries on
     * an unlicensed run and an assertion that silently skips there is
     * exactly the hole this suite exists to close: the capability string is
     * a static declaration and is wrong on every tier at once if it is wrong
     * at all.
     *
     * update-term-seo-meta sits at manage_categories: a term's SEO fields
     * shape a whole archive, and every other term write in the plugin
     * (set-term-meta, update-term) is gated there too.
     */
    private const EXPECTED_CAPABILITIES = [
        'wpmcp/generate-schema-markup'  => 'edit_posts',
        'wpmcp/generate-meta-tags'      => 'edit_posts',
        'wpmcp/get-social-meta'         => 'edit_posts',
        'wpmcp/set-social-image'        => 'edit_posts',
        'wpmcp/get-term-seo-meta'       => 'edit_posts',
        'wpmcp/update-term-seo-meta'    => 'manage_categories',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array<string, \WPMCP\MCP\Ability> */
    private function declared_abilities(): array
    {
        $out = [];
        foreach (Plugin::instance()->registrar()->declared() as $ability) {
            $out[ $ability->name ] = $ability;
        }

        return $out;
    }

    public function test_every_pro_seo_ability_declares_the_expected_capability(): void
    {
        $declared = $this->declared_abilities();

        foreach (self::EXPECTED_CAPABILITIES as $name => $capability) {
            if (! isset($declared[$name])) {
                // The postmeta-backed tools only declare when a supported SEO
                // plugin is active.
                continue;
            }
            $this->assertSame(
                $capability,
                $declared[$name]->capability,
                "{$name} must declare {$capability}"
            );
        }

        $this->assertArrayHasKey('wpmcp/generate-schema-markup', $declared);
        $this->assertArrayHasKey('wpmcp/generate-meta-tags', $declared);
    }

    /**
     * Permission outcome for the two pro reads. Where the tier exposes them,
     * this goes through the ability's own check_permissions(); where it does
     * not, it asserts the same thing against the declared capability, so the
     * case cannot quietly become a skip on an unlicensed run and stop
     * defending anything.
     */
    public function test_generate_schema_markup_denies_subscriber_and_allows_edit_posts(): void
    {
        $this->assertPermissionOutcome('wpmcp/generate-schema-markup');
    }

    public function test_generate_meta_tags_denies_subscriber_and_allows_edit_posts(): void
    {
        $this->assertPermissionOutcome('wpmcp/generate-meta-tags');
    }

    public function test_set_social_image_and_term_read_deny_subscriber_and_allow_edit_posts(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->assertPermissionOutcome('wpmcp/set-social-image');
        $this->assertPermissionOutcome('wpmcp/get-term-seo-meta');
    }

    /**
     * An author holds edit_posts but not manage_categories, so the term SEO
     * write must refuse them while an editor gets through.
     */
    public function test_update_term_seo_meta_requires_manage_categories(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $name     = 'wpmcp/update-term-seo-meta';
        $declared = $this->declared_abilities();
        $this->assertArrayHasKey($name, $declared);
        $this->assertSame('manage_categories', $declared[$name]->capability);

        $exposed = wp_get_abilities()[$name] ?? null;

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertNotTrue(null !== $exposed ? $exposed->check_permissions() : current_user_can('manage_categories'));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertTrue(null !== $exposed ? $exposed->check_permissions() : current_user_can('manage_categories'));
    }

    public function test_get_social_meta_denies_subscriber_and_allows_edit_posts(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->assertPermissionOutcome('wpmcp/get-social-meta');
    }

    private function assertPermissionOutcome(string $name): void
    {
        $declared = $this->declared_abilities();
        $this->assertArrayHasKey($name, $declared, "{$name} must be declared");

        $exposed    = wp_get_abilities()[$name] ?? null;
        $capability = $declared[$name]->capability;

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue(
            null !== $exposed ? $exposed->check_permissions() : current_user_can($capability),
            "{$name} must deny a subscriber"
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            null !== $exposed ? $exposed->check_permissions() : current_user_can($capability),
            "{$name} must allow a user holding edit_posts"
        );
    }
}
