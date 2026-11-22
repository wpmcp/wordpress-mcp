<?php

namespace WPMCP\Tests\Free\Edition;

use WPMCP\Plugin;
use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\BlockBuilder\Block_Registry;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\CustomCode\Custom_Code_Renderer;
use WPMCP\Tools\CustomCode\Custom_Code_Store;
use WPMCP\Tools\ThemeBuilder\Dynamic\Binding_Resolver;
use WPMCP\Tools\ThemeBuilder\Dynamic\Dynamic_Templates;
use WPMCP\Tools\WidgetBuilder\Widget_Registry;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

/**
 * A site that drops back from Pro to the free build keeps showing what the
 * Pro tools made: custom blocks and widgets still render, stored custom CSS
 * still prints, dynamic templates still resolve, and chat conversations are
 * still purged with their owner. Only creating and editing need Pro, so the
 * content here is written through the same stores the Pro tools write
 * through. Runs in every tree; in the free tree (bin/free-tree-smoke.sh, the
 * free zip) it is the downgrade itself.
 *
 * @group free-tree
 */
final class DowngradeRenderTest extends \WP_UnitTestCase
{
    public function test_the_display_code_is_public(): void
    {
        foreach (
            [
                'src/Tools/BlockBuilder/Block_Registry.php',
                'src/Tools/BlockBuilder/Block_Spec_Store.php',
                'src/Tools/WidgetBuilder/Widget_Registry.php',
                'src/Tools/WidgetBuilder/Widget_Spec_Store.php',
                'src/Tools/CustomCode/Custom_Code_Renderer.php',
                'src/Tools/ThemeBuilder/Dynamic/Dynamic_Templates.php',
                'src/Tools/ThemeBuilder/Dynamic/Binding_Resolver.php',
                'src/Pro/Chat/Conversation_Store.php',
            ] as $path
        ) {
            $this->assertFalse(Edition_Tree::is_pro_path($path), "{$path} displays Pro-made content, so it must stay in the free build");
            $this->assertFileExists(Edition_Tree::root() . '/' . $path);
        }
    }

    public function test_the_runtime_hooks_are_wired_without_pro(): void
    {
        $this->assertNotFalse(has_action('elementor/widgets/register', [Widget_Registry::class, 'register']), 'custom widgets are not registered with Elementor');
        $this->assertNotFalse(has_action('init', [Block_Registry::class, 'register']), 'custom blocks are not registered');
        $this->assertNotFalse(has_action('wp', [Dynamic_Templates::class, 'boot']), 'dynamic templates do not boot');
        $this->assertNotFalse(has_filter('wpmcp_site_part_rendered', [Binding_Resolver::class, 'filter_rendered']), 'dynamic bindings are not resolved');
        $this->assertTrue(post_type_exists(Widget_Spec_Store::POST_TYPE));
        $this->assertTrue(post_type_exists(Block_Spec_Store::POST_TYPE));
        $this->assertTrue(post_type_exists(Conversation_Store::POST_TYPE));
    }

    public function test_a_custom_block_made_with_pro_still_renders(): void
    {
        $id = Block_Spec_Store::create([
            'name'       => 'downgrade-callout',
            'title'      => 'Downgrade callout',
            'attributes' => [
                ['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Still here'],
            ],
            'template'   => '<div class="callout"><h4>{{heading}}</h4></div>',
        ]);
        $this->assertIsInt($id, 'the block spec was not stored');

        Block_Registry::register();
        $registry = \WP_Block_Type_Registry::get_instance();
        $names = array_keys($registry->get_all_registered());
        $ours = array_values(array_filter($names, static fn (string $n): bool => str_ends_with($n, 'downgrade-callout')));
        $this->assertCount(1, $ours, 'the custom block is not registered');

        $html = render_block(['blockName' => $ours[0], 'attrs' => ['heading' => 'Rendered after downgrade'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
        $this->assertStringContainsString('Rendered after downgrade', $html);
        $registry->unregister($ours[0]);
    }

    public function test_custom_css_made_with_pro_still_prints(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        Custom_Code_Store::set_css('.downgrade-check { color: #123456; }', $post_id);
        $this->go_to(get_permalink($post_id));

        ob_start();
        Custom_Code_Renderer::print_css();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('#123456', $out, 'stored custom CSS no longer prints');
    }

    public function test_chat_conversations_are_still_purged_with_their_owner(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $user = self::factory()->user->create(['role' => 'administrator']);
        $post = self::factory()->post->create(['post_type' => Conversation_Store::POST_TYPE, 'post_author' => $user, 'post_status' => 'private']);
        update_post_meta($post, '_wpmcp_chat_owner', $user);

        wp_delete_user($user, self::factory()->user->create());

        $this->assertNull(get_post($post), 'a conversation outlived its owner on the free build');
    }

    public function test_creating_and_editing_stay_pro(): void
    {
        $manifest = Edition_Tree::ability_manifest()['abilities'];
        foreach (['wpmcp/create-custom-block', 'wpmcp/update-custom-block', 'wpmcp/create-custom-widget', 'wpmcp/add-custom-css', 'wpmcp/compile-custom-widget'] as $name) {
            if (Edition_Tree::has_pro_code()) {
                $this->assertSame('pro', $manifest[ $name ] ?? null, "{$name} must stay a Pro tool");
            } else {
                $this->assertArrayNotHasKey($name, $manifest);
                $this->assertNull(Plugin::instance()->registrar()->get($name), "{$name} registered without Pro");
            }
        }
    }
}
