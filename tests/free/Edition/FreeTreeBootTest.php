<?php

namespace WPMCP\Tests\Free\Edition;

use WPMCP\Edition\Edition;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Plugin;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * The free tree boots on its own. Runs in both trees: with pro/ present it
 * checks that Pro registered its edition slots; with pro/ absent (the free
 * zip, and bin/free-tree-smoke.sh) it checks that the plugin booted, every
 * free ability registered, no Pro code is loadable and free tools run.
 *
 * @group free-tree
 */
final class FreeTreeBootTest extends \WP_UnitTestCase
{

    public function test_the_plugin_booted(): void
    {
        $this->assertTrue(defined('WPMCP_VERSION'));
        $this->assertTrue(defined('WPMCP_PREMIUM_BUILD'));
        $this->assertNotEmpty(Plugin::instance()->registrar()->all(), 'no ability registered at all');
    }

    public function test_edition_slots_match_the_tree(): void
    {
        $edition = Edition::from_hooks();
        if (Edition_Tree::has_pro_dir()) {
            $this->assertTrue($edition->has_contributors(), 'pro/ is present but registered no edition slot');
            $this->assertTrue(defined('WPMCP_PRO_BUILD'));
            return;
        }
        // Before the split Plugin.php still runs the Pro code itself.
        $this->assertFalse($edition->has_contributors(), 'a contributor registered without pro/');
        if (! Edition_Tree::has_pro_code()) {
            $this->assertFalse(defined('WPMCP_PRO_BUILD'));
            $this->assertFalse(class_exists('WPMCP\\Pro\\Plugin_Slots'), 'the Pro slot class loads without pro/');
        }
    }

    public function test_every_free_ability_registers(): void
    {
        $manifest = Edition_Tree::ability_manifest();
        $registered = RegisteredAbilities::manifest_map();
        $missing = [];
        foreach ($manifest['abilities'] as $name => $tier) {
            if ('free' === $tier && ! isset($registered[ $name ])) {
                $missing[] = $name;
            }
        }
        $this->assertSame([], $missing, 'free abilities that did not register');
        foreach ($registered as $name => $tier) {
            if (isset($manifest['abilities'][ $name ])) {
                $this->assertSame($manifest['abilities'][ $name ], $tier, "{$name} changed tier");
            }
        }
    }

    public function test_free_read_tools_run(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $post_id = self::factory()->post->create(['post_title' => 'Free tree smoke']);
        $registrar = Plugin::instance()->registrar();

        foreach (['wpmcp/list-posts' => ['post_type' => 'post'], 'wpmcp/get-post' => ['post_id' => $post_id]] as $name => $input) {
            $ability = $registrar->get($name);
            $this->assertNotNull($ability, "{$name} is not registered");
            $result = call_user_func($ability->handler, $input);
            $this->assertIsArray($result, "{$name} returned no result");
            $this->assertStringContainsString('Free tree smoke', (string) wp_json_encode($result), "{$name} did not return the post");
        }
    }
}
