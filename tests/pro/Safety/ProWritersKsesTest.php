<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Pro\Gate;
use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Integrations\Operation_Error;
use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Kses\Kses_Markup;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;
use WPMCP\Tools\Analysis\Fix_Pass;
use WPMCP\Tests\Pro\Builders\CornerstoneElementEditingTest;
use WPMCP\Tools\Builders\Avada_Library;
use WPMCP\Tools\Builders\Cornerstone_Content;
use WPMCP\Tools\Builders\Update_Builder_Content;
use WPMCP\Tools\Elementor\Element_Tree;
use WPMCP\Tools\Elementor\Elementor_Page_Data;

/**
 * Issue #1243, the Pro writers, for a user without unfiltered_html (a site
 * administrator on a network): builder layouts, library copies, Oxygen,
 * Formidable JSON, Elementor and Cornerstone values kses would rewrite are
 * refused with unfiltered_html_required and nothing is stored or created.
 * Plain post content (fix passes; the stock image insert is covered in
 * ContentWriteFilterProTest) is sanitized as the editor does it and the
 * response reports what kses removed.
 */
class ProWritersKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_avada_active');
        remove_all_filters('wpmcp_flatsome_active');
        remove_all_filters('wpmcp_cornerstone_active');
        remove_all_filters('wpmcp_cornerstone_engine');
        foreach (['element_category', 'template_category'] as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }
        parent::tearDown();
    }

    private function refusal(callable $write): \WP_Error
    {
        try {
            $out = $write();
        } catch (Post_Write_Refused $e) {
            return $e->error();
        } catch (Operation_Error $e) {
            return new \WP_Error($e->error_code(), $e->getMessage(), $e->error_data());
        }
        $this->assertWPError($out, 'The write kses would rewrite was not refused.');

        return $out;
    }

    private function page(string $content, string $type = 'page'): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => 'publish']);
        $this->store_raw($id, $content);

        return $id;
    }

    private function posts_of(string $type): int
    {
        return count(get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    }

    public function test_a_fix_pass_on_a_page_holding_stored_markup_reports_what_kses_removed(): void
    {
        $id = $this->page('<p><a href="/x">click here</a></p>' . Kses_Markup::STORED);
        $this->log_in_without_unfiltered_html();

        $out = Fix_Pass::finish('fix-link-text', get_post($id), ['apply' => true], [['x' => 1]], [], [['offset' => 3, 'length' => 0, 'replacement' => '']]);

        $this->assertTrue($out['sanitized'] ?? false, (string) wp_json_encode($out));
        $this->assertContains('<script> element', $out['removed']);
    }

    public function test_saving_an_avada_page_kses_would_rewrite_to_the_library_creates_nothing(): void
    {
        add_filter('wpmcp_avada_active', '__return_true');
        register_taxonomy('element_category', ['fusion_element'], ['hierarchical' => true]);
        register_taxonomy('template_category', ['fusion_template'], ['hierarchical' => true]);
        $id = $this->page('[fusion_builder_container][fusion_code]' . Kses_Markup::STORED . '[/fusion_code][/fusion_builder_container]');
        $this->log_in_without_unfiltered_html();
        $before = $this->posts_of('fusion_template');

        $this->assert_kses_refused($this->refusal(fn () => Avada_Library::save_template($id, ['element' => ['title' => 'Copy']])));
        $this->assertSame($before, $this->posts_of('fusion_template'));
    }

    public function test_a_formidable_action_kses_would_rewrite_is_refused_without_formidable_loaded(): void
    {
        if (class_exists('FrmDb')) {
            $this->markTestSkipped('Formidable is loaded: its own save stores the action.');
        }
        $this->log_in_without_unfiltered_html();
        $action = self::factory()->post->create(['post_type' => 'frm_form_actions', 'post_excerpt' => 'email', 'menu_order' => 1, 'post_content' => '{}']);
        $save   = new \ReflectionMethod(Formidable_Integration::class, 'save_action');

        $error = $this->refusal(fn () => $save->invoke(null, get_post($action), ['email_message' => Kses_Markup::UNSAFE]));

        $this->assert_kses_refused($error);
        $this->assertSame('{}', get_post($action)->post_content);
    }

    public function test_an_elementor_tree_write_with_a_new_string_kses_would_change_is_refused_before_the_snapshot(): void
    {
        $tree = [['id' => 'a1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => '<p>Old</p>'], 'elements' => []]];
        $id   = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        update_post_meta($id, '_elementor_data', wp_slash((string) wp_json_encode($tree)));
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        $this->log_in_without_unfiltered_html();

        $changed                         = $tree;
        $changed[0]['settings']['html'] = Kses_Markup::UNSAFE;
        $out                             = Element_Tree::write($id, $changed, 'update-element', []);

        $this->assert_kses_refused($out);
        $this->assertSame($tree, Elementor_Page_Data::get($id));
        $this->assertSame(0, Snapshot_Store::row_count());
    }

    public function test_a_cornerstone_value_kses_would_change_is_refused_before_the_snapshot(): void
    {
        require_once dirname(__DIR__, 2) . '/support/cornerstone-engine-double.php';
        $engine = new \WPMCP\Tests\Support\Cornerstone_Engine_Double();
        add_filter('wpmcp_cornerstone_active', '__return_true');
        add_filter('wpmcp_cornerstone_engine', static fn () => $engine);
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- cs-content --><div>Hello live</div><!-- cs-content-end -->']);
        update_post_meta($id, '_cornerstone_data', wp_slash(CornerstoneElementEditingTest::DATA));
        $this->log_in_without_unfiltered_html();
        $raw = Cornerstone_Content::raw($id);

        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'cornerstone', 'operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['text_content' => Kses_Markup::UNSAFE], 'expected_hash' => hash('sha256', $raw)]);

        $this->assert_kses_refused($out);
        $this->assertSame($raw, Cornerstone_Content::raw($id));
        $this->assertSame(0, Snapshot_Store::row_count());
    }
}
