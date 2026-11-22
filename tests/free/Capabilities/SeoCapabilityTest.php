<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the free half of the SEO domain: get-seo-status,
 * get-seo-meta and update-seo-meta require edit_posts, and create-redirect
 * manage_options. get-seo-status is always registered; the plugin-backed
 * tools are gated behind an active SEO plugin, matching
 * SeoAbilitiesRegistrationTest's guard.
 *
 * The Pro SEO abilities (generate-schema-markup, generate-meta-tags,
 * get-social-meta, set-social-image, get-term-seo-meta and
 * update-term-seo-meta) are asserted the same way in
 * tests/pro/Capabilities/SeoCapabilityProTest, which ships with the Pro
 * code. Together the two halves defend that no future edit to
 * register_seo_abilities() can silently widen one ability's capability
 * while the others stay correct. Tier is a separate concern and stays
 * covered by the per-tool tests.
 */
class SeoCapabilityTest extends \WP_UnitTestCase
{
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

    public function test_get_seo_status_requires_edit_posts(): void
    {
        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertArrayHasKey('wpmcp/get-seo-status', $abilities);
        $this->assertSame('edit_posts', $abilities['wpmcp/get-seo-status']->capability);
    }

    public function test_get_seo_status_denies_subscriber_and_allows_edit_posts(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue(
            $abilities['wpmcp/get-seo-status']->check_permissions(),
            'wpmcp/get-seo-status must deny a subscriber'
        );

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue(
            $abilities['wpmcp/get-seo-status']->check_permissions(),
            'wpmcp/get-seo-status must allow a user holding edit_posts'
        );
    }

    public function test_meta_tools_require_edit_posts_when_an_seo_plugin_is_active(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertSame('edit_posts', $abilities['wpmcp/get-seo-meta']->capability);
        $this->assertSame('edit_posts', $abilities['wpmcp/update-seo-meta']->capability);

        $wp_abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue($wp_abilities['wpmcp/update-seo-meta']->check_permissions());

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertTrue($wp_abilities['wpmcp/update-seo-meta']->check_permissions());
    }

    /**
     * The declared capability of every free SEO ability. Asserted against
     * declared() rather than all(), so the check does not depend on the tier
     * the run happens to expose: the capability string is a static
     * declaration and is wrong on every tier at once if it is wrong at all.
     *
     * create-redirect sits deliberately higher. Redirects are a site-wide
     * routing change, not a per-post edit, so it is listed explicitly rather
     * than swept in with the rest.
     */
    private const EXPECTED_CAPABILITIES = [
        'wpmcp/get-seo-status'          => 'edit_posts',
        'wpmcp/get-seo-meta'            => 'edit_posts',
        'wpmcp/update-seo-meta'         => 'edit_posts',
        'wpmcp/create-redirect'         => 'manage_options',
    ];

    /** @return array<string, \WPMCP\MCP\Ability> */
    private function declared_abilities(): array
    {
        $out = [];
        foreach (Plugin::instance()->registrar()->declared() as $ability) {
            $out[ $ability->name ] = $ability;
        }

        return $out;
    }

    public function test_every_seo_ability_declares_the_expected_capability(): void
    {
        $declared = $this->declared_abilities();

        foreach (self::EXPECTED_CAPABILITIES as $name => $capability) {
            if (! isset($declared[$name])) {
                // The postmeta-backed tools only declare when a supported SEO
                // plugin is active, and redirects are their own group.
                continue;
            }
            $this->assertSame(
                $capability,
                $declared[$name]->capability,
                "{$name} must declare {$capability}"
            );
        }

        $this->assertArrayHasKey('wpmcp/get-seo-status', $declared);
    }

    /**
     * The SEO domain is wider than this suite's map (it also carries the
     * linking, redirect and orphan tools), so the sweep asserts the floor
     * rather than the exact string: no ability in the domain may sit below
     * edit_posts. A tool added later with 'read' fails here even though it
     * has no entry above.
     */
    public function test_no_seo_domain_ability_sits_below_edit_posts(): void
    {
        $allowed = ['edit_posts', 'manage_categories', 'manage_options'];
        $seen    = 0;

        foreach ($this->declared_abilities() as $name => $ability) {
            if ('seo' !== $ability->domain) {
                continue;
            }
            $seen++;
            $this->assertContains(
                $ability->capability,
                $allowed,
                "{$name} declares {$ability->capability}, which is below edit_posts"
            );
        }

        $this->assertGreaterThanOrEqual(2, $seen, 'Expected the SEO domain to be registered');
    }
}
