<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Flatsome_Content, Get_Builder_Content, Update_Builder_Content};

/**
 * End to end through the builder pair for Flatsome (UX Builder): detection
 * while the theme (or a child of it) is active, the tree read, each element
 * operation with exact rollback, UX Blocks (the `blocks` post type) listed,
 * edited, created from a page element and placed in a page with [block id],
 * the element catalog, and the refusals that write nothing.
 *
 * Flatsome is a commercial theme that is not installed here, so pages are
 * written as UX Builder stores them, the `blocks` post type is registered
 * the way the theme registers it, and the theme's presence is toggled
 * through the wpmcp_flatsome_active filter.
 */
class FlatsomeAdapterTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (! post_type_exists('blocks')) {
            register_post_type('blocks', ['public' => false, 'show_ui' => true]);
        }
        add_filter('wpmcp_flatsome_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_flatsome_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function page(string $content, array $meta = [], string $type = 'page'): int
    {
        $post_id = self::factory()->post->create(['post_type' => $type, 'post_content' => wp_slash($content), 'post_status' => 'publish']);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    private function flatsome_page(): int
    {
        return $this->page(FlatsomeShortcodesTest::FIXTURE, ['_wp_page_template' => 'page-blank.php', '_footer' => 'normal']);
    }

    /** A write with the page's current hash, as a caller that just read it passes. */
    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'flatsome', 'expected_hash' => Flatsome_Content::hash($post_id)] + $args);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function state(int $post_id): array
    {
        clean_post_cache($post_id);

        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    private function tree(int $post_id): array
    {
        return (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'];
    }

    private function snapshot_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    private function detect(int $post_id): string
    {
        return (new Detect_Builder())->handle(['post_id' => $post_id])['builder'];
    }

    // ------------------------------------------------------------ detection

    public function test_detects_flatsome_content_only_while_the_theme_is_active(): void
    {
        $page   = $this->flatsome_page();
        $ux     = $this->page('[ux_text]<p>Hi</p>[/ux_text]');
        $row    = $this->page("[row]\n[col span=\"12\"][/col]\n[/row]");
        $blank  = $this->page('', [], 'blocks');

        foreach ([$page, $ux, $row, $blank] as $post_id) {
            $this->assertSame('flatsome', $this->detect($post_id));
        }

        remove_all_filters('wpmcp_flatsome_active');
        add_filter('wpmcp_flatsome_active', '__return_false');
        $this->assertSame('classic', $this->detect($page));
        $this->assertSame('classic', $this->detect($blank));
    }

    public function test_the_theme_check_follows_the_parent_template(): void
    {
        remove_all_filters('wpmcp_flatsome_active');
        $this->assertFalse(Flatsome_Content::theme_active());

        $template = static fn (): string => 'flatsome';
        add_filter('template', $template);
        $this->assertTrue(Flatsome_Content::theme_active(), 'A Flatsome child theme has template flatsome');
        remove_filter('template', $template);
    }

    public function test_other_builders_win_and_lookalikes_do_not_match(): void
    {
        $wpbakery  = $this->page('[vc_row][vc_column][/vc_column][/vc_row]');
        $gutenberg = $this->page('<!-- wp:paragraph --><p>[section]</p><!-- /wp:paragraph -->');
        $lookalike = $this->page('<p>[sections] and [rowing] are not layout</p>');
        $plain     = $this->page('<p>Plain</p>');

        $this->assertSame('wpbakery', $this->detect($wpbakery));
        $this->assertSame('gutenberg', $this->detect($gutenberg));
        $this->assertSame('classic', $this->detect($lookalike));
        $this->assertSame('classic', $this->detect($plain));
    }

    // ----------------------------------------------------------------- read

    public function test_read_returns_tree_content_hash_and_placed_blocks(): void
    {
        $post_id = $this->flatsome_page();

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame('flatsome', $out['builder']);
        $this->assertSame($post_id, $out['post_id']);
        $this->assertTrue($out['plugin_active']);
        $this->assertSame('page', $out['post_type']);
        $this->assertSame(FlatsomeShortcodesTest::FIXTURE, $out['content']);
        $this->assertSame(hash('sha256', FlatsomeShortcodesTest::FIXTURE), $out['hash']);
        $this->assertSame('section', $out['tree'][0]['tag']);
        $this->assertSame(['17'], $out['blocks']);
    }

    // ---------------------------------------------------------- round trips

    public function test_update_add_remove_and_move_each_roll_back_exactly(): void
    {
        $post_id = $this->flatsome_page();
        $before  = $this->state($post_id);

        $update = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'text' => '<p>Rewritten \\ copy</p>']);
        $this->assertSame('0.0.0.0', $update['path']);
        $this->assertSame(Flatsome_Content::hash($post_id), $update['hash']);
        $this->assertSame('<p>Rewritten \\ copy</p>', $this->tree($post_id)[0]['children'][0]['children'][0]['children'][0]['text']);
        $this->assertTrue(Rollback_Service::restore_operation($update['operation_id']));
        $this->assertSame($before, $this->state($post_id));

        $add = $this->update($post_id, ['operation' => 'add', 'to' => '0.0.0', 'index' => 0, 'element' => ['tag' => 'title', 'attrs' => ['text' => 'Hello']]]);
        $this->assertSame('0.0.0.0', $add['path']);
        $this->assertStringContainsString('[title text="Hello"][ux_text', get_post($post_id)->post_content);
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '2']);
        $this->assertStringNotContainsString('ux_banner', get_post($post_id)->post_content);
        Rollback_Service::restore_operation($remove['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $move = $this->update($post_id, ['operation' => 'move', 'path' => '1', 'to' => '', 'index' => 0]);
        $this->assertSame('block', $this->tree($post_id)[0]['tag']);
        Rollback_Service::restore_operation($move['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_whole_content_replace_and_a_first_layout_on_a_blank_page_roll_back(): void
    {
        $post_id = $this->flatsome_page();
        $before  = $this->state($post_id);
        $content = '[section][row][col span="12"][ux_text]<p>New</p>[/ux_text][/col][/row][/section]';

        $out = $this->update($post_id, ['content' => $content]);
        $this->assertSame($content, get_post($post_id)->post_content);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $blank  = $this->page('');
        $before = $this->state($blank);
        $first  = (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'flatsome', 'operation' => 'add', 'to' => '', 'element' => ['tag' => 'section', 'children' => [['tag' => 'row']]]]);
        $this->assertSame('[section][row][/row][/section]', get_post($blank)->post_content);
        $this->assertSame('flatsome', $this->detect($blank));
        Rollback_Service::restore_operation($first['operation_id']);
        $this->assertSame($before, $this->state($blank));
    }

    // ------------------------------------------------------------ UX Blocks

    public function test_a_ux_block_is_edited_like_a_page_and_rolls_back(): void
    {
        $block  = $this->page('[ux_text]<p>Promo</p>[/ux_text]' . "\n" . '[button text="Go"]', [], 'blocks');
        $before = $this->state($block);

        $read = (new Get_Builder_Content())->handle(['post_id' => $block]);
        $this->assertSame('blocks', $read['post_type']);

        $out = $this->update($block, ['operation' => 'update', 'path' => '1', 'attrs' => ['text' => 'Buy']]);
        $this->assertSame('[ux_text]<p>Promo</p>[/ux_text]' . "\n" . '[button text="Buy"]', get_post($block)->post_content);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($block));
    }

    public function test_placing_a_ux_block_in_a_page_and_rolling_it_back(): void
    {
        $block   = $this->page('[ux_text]<p>Promo</p>[/ux_text]', [], 'blocks');
        $post_id = $this->flatsome_page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '0.0.1', 'index' => 0, 'element' => ['block' => $block]]);

        $this->assertNotWPError($out);
        $this->assertSame('0.0.1.0', $out['path']);
        $this->assertStringContainsString('[block id="' . $block . '"][ux_image', get_post($post_id)->post_content);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_placing_a_missing_block_is_refused_before_any_snapshot(): void
    {
        $post_id = $this->flatsome_page();
        $page    = self::factory()->post->create(['post_type' => 'page']);
        $count   = $this->snapshot_count();

        foreach ([999999, $page, 'abc'] as $id) {
            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['block' => $id]]);
            $this->assertSame('invalid_flatsome_operation', $out->get_error_code(), (string) $id);
        }
        $this->assertSame($count, $this->snapshot_count());
        $this->assertSame(FlatsomeShortcodesTest::FIXTURE, get_post($post_id)->post_content);
    }

    public function test_save_template_creates_a_ux_block_and_rollback_trashes_it(): void
    {
        $post_id = $this->flatsome_page();
        $page    = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'save_template', 'path' => '2', 'element' => ['title' => 'Hero banner']]);

        $this->assertNotWPError($out);
        $saved = get_post($out['template_id']);
        $this->assertSame('blocks', $saved->post_type);
        $this->assertSame('Hero banner', $saved->post_title);
        $this->assertSame('publish', $saved->post_status);
        $this->assertSame('[ux_banner height="500px" bg="12"][text_box width="60"]<h3>Banner</h3>[/text_box][/ux_banner]', $saved->post_content);
        $this->assertSame($page, $this->state($post_id), 'The page itself is not touched');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($saved->ID));

        $whole = $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'Whole page']]);
        $this->assertSame(FlatsomeShortcodesTest::FIXTURE, get_post($whole['template_id'])->post_content);
    }

    public function test_save_template_refusals_create_nothing(): void
    {
        $post_id = $this->flatsome_page();

        $this->assertSame('invalid_flatsome_operation', $this->update($post_id, ['operation' => 'save_template', 'path' => '0'])->get_error_code());
        $this->assertSame('invalid_flatsome_operation', $this->update($post_id, ['operation' => 'save_template', 'path' => '9', 'element' => ['title' => 'X']])->get_error_code());
        $this->assertSame([], get_posts(['post_type' => 'blocks', 'post_status' => 'any']));
    }

    // ------------------------------------------------------------- refusals

    public function test_refusals_write_nothing_and_take_no_snapshot(): void
    {
        $post_id = $this->flatsome_page();
        $before  = $this->state($post_id);
        $count   = $this->snapshot_count();
        $handler = new Update_Builder_Content();
        $op      = ['post_id' => $post_id, 'builder' => 'flatsome', 'operation' => 'remove', 'path' => '1'];

        $this->assertSame('missing_expected_hash', $handler->handle($op)->get_error_code());
        $this->assertSame('stale_expected_hash', $handler->handle($op + ['expected_hash' => str_repeat('0', 64)])->get_error_code());
        $this->assertSame('invalid_flatsome_operation', $this->update($post_id, ['operation' => 'explode'])->get_error_code());
        $this->assertSame('invalid_flatsome_operation', $this->update($post_id, ['operation' => 'remove', 'path' => '9'])->get_error_code());
        $this->assertSame('invalid_flatsome_content', $this->update($post_id, [])->get_error_code());

        $wpbakery = $this->page('[vc_row][/vc_row]');
        $this->assertSame('unsupported_builder', $this->update($wpbakery, ['operation' => 'remove', 'path' => '0'])->get_error_code());

        remove_all_filters('wpmcp_flatsome_active');
        add_filter('wpmcp_flatsome_active', '__return_false');
        $missing = $this->update($post_id, ['operation' => 'remove', 'path' => '1']);
        $this->assertSame('plugin_missing', $missing->get_error_code());
        $this->assertStringContainsString('Flatsome', $missing->get_error_message());

        $this->assertSame($count, $this->snapshot_count());
        $this->assertSame($before, $this->state($post_id));
    }

    // --------------------------------------------------------------- scopes

    public function test_templates_scope_lists_the_ux_blocks(): void
    {
        $block = self::factory()->post->create(['post_type' => 'blocks', 'post_title' => 'Footer promo', 'post_name' => 'footer-promo', 'post_status' => 'publish', 'post_content' => '[ux_text]A[/ux_text][gap]']);

        $out = (new Get_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'flatsome']);

        $this->assertSame('flatsome', $out['builder']);
        $this->assertSame([['id' => $block, 'title' => 'Footer promo', 'slug' => 'footer-promo', 'status' => 'publish', 'elements' => 2, 'shortcode' => '[block id="' . $block . '"]']], $out['templates']);
    }

    public function test_catalog_lists_the_elements_and_one_elements_options(): void
    {
        $out = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'flatsome']);
        $tags = array_column($out['elements'], 'tag');
        foreach (['section', 'row', 'col', 'ux_text', 'ux_image', 'button', 'block'] as $tag) {
            $this->assertContains($tag, $tags);
        }

        $one = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'flatsome', 'element' => 'col']);
        $this->assertSame('col', $one['element']['tag']);
        $this->assertContains('span', array_column($one['element']['options'], 'name'));

        $this->assertSame('element_not_found', (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'flatsome', 'element' => 'nope'])->get_error_code());
        $this->assertSame('unsupported_builder', (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'flatsome'])->get_error_code());
    }
}
