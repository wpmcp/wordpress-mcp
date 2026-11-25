<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Tools\Builders\Beaver_Builder_Content;
use WPMCP\Tools\Builders\Breakdance_Content;
use WPMCP\Tools\Builders\Breakdance_Tree;
use WPMCP\Tools\Builders\Bricks_Content;
use WPMCP\Tools\Builders\Divi_Content;
use WPMCP\Tools\Builders\Oxygen_Classic_Content;
use WPMCP\Tools\Builders\Thrive_Content;

/**
 * Builder fields that hold code kses leaves alone (a script with no tags,
 * PHP, a function call), on the storage layer of the builders that keep
 * their layout in post meta, for a user without unfiltered_html (on a
 * network, a site administrator who is not a super admin; on a single site,
 * an administrator the capability was taken from): new or changed code is
 * refused with unfiltered_html_required (403) and nothing is written. Code
 * the page or the copied source already holds where code goes is kept, a
 * field that only shows code is text, and a user with unfiltered_html (a
 * super admin, or a single-site administrator) writes any of it.
 */
final class BuilderCodeFieldsTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const SCRIPT = 'document.body.dataset.owner = "x";';

    private function refusal(callable $write): \WP_Error
    {
        try {
            $write();
        } catch (Post_Write_Refused $e) {
            return $e->error();
        }
        $this->fail('The builder write holding new code was not refused.');
    }

    private function assert_code_refused(callable $write): void
    {
        $error = $this->refusal($write);
        $this->assert_kses_refused($error);
        $this->assertSame(403, $error->get_error_data()['status']);
    }

    private function log_in_with_unfiltered_html(): void
    {
        remove_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10);
        $id = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($id);
        }
        wp_set_current_user($id);
        $this->assertTrue(current_user_can('unfiltered_html'));
    }

    // ------------------------------------------------------------- Bricks

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function bricks_element(string $id, string $name, array $settings): array
    {
        return ['id' => $id, 'name' => $name, 'parent' => 0, 'children' => [], 'settings' => $settings];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function bricks_code(): array
    {
        return [
            'running code element script' => [self::bricks_element('c1', 'code', ['executeCode' => true, 'javascriptCode' => self::SCRIPT])],
            'running code element PHP'    => [self::bricks_element('c1', 'code', ['executeCode' => true, 'code' => '<?php echo get_option("admin_email"); ?>'])],
            'running code element style'  => [self::bricks_element('c1', 'code', ['executeCode' => true, 'cssCode' => 'body{display:none}'])],
            'query editor'                => [self::bricks_element('c1', 'container', ['hasLoop' => true, 'query' => ['useQueryEditor' => true, 'queryEditor' => 'return ["post_type" => "page"];']])],
            'echo tag'                    => [self::bricks_element('c1', 'text', ['text' => 'Hi {echo:get_option(admin_email)}'])],
            'interaction call'            => [self::bricks_element('c1', 'button', ['text' => 'Go', '_interactions' => [['id' => 'i1', 'trigger' => 'click', 'action' => 'javascript', 'jsFunction' => 'eval', 'jsFunctionArgs' => [['id' => 'a1', 'jsFunctionArg' => self::SCRIPT]]]]])],
            'event handler attribute'     => [self::bricks_element('c1', 'button', ['text' => 'Go', '_attributes' => [['id' => 'a1', 'name' => 'onclick', 'value' => self::SCRIPT]]])],
            'script url attribute'        => [self::bricks_element('c1', 'text-link', ['text' => 'Go', '_attributes' => [['id' => 'a1', 'name' => 'href', 'value' => 'javascript:void(0)']]])],
        ];
    }

    /**
     * @dataProvider bricks_code
     * @param array<string, mixed> $element
     */
    public function test_new_code_in_a_bricks_layout_is_refused(array $element): void
    {
        $page  = self::factory()->post->create(['post_type' => 'page']);
        $old   = [self::bricks_element('t1', 'text', ['text' => '<p>Old</p>'])];
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash($old));
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Bricks_Content::save($page, [...$old, $element]));

        $this->assertSame($old, Bricks_Content::get($page));
    }

    public function test_turning_on_a_bricks_code_element_that_shows_its_code_is_refused(): void
    {
        $page  = self::factory()->post->create(['post_type' => 'page']);
        $shown = self::bricks_element('c1', 'code', ['javascriptCode' => self::SCRIPT]);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash([$shown]));
        $this->log_in_without_unfiltered_html();

        $run                            = $shown;
        $run['settings']['executeCode'] = true;
        $this->assert_code_refused(fn () => Bricks_Content::save($page, [$run]));

        // A code element that only shows its code prints it escaped: text.
        $shown['settings']['javascriptCode'] = 'console.log(1);';
        Bricks_Content::save($page, [$shown]);
        $this->assertSame([$shown], Bricks_Content::get($page));
    }

    public function test_bricks_code_the_page_or_the_copied_source_holds_is_kept(): void
    {
        $code   = self::bricks_element('c1', 'code', ['executeCode' => true, 'javascriptCode' => self::SCRIPT, '_attributes' => [['id' => 'a1', 'name' => 'onclick', 'value' => self::SCRIPT]]]);
        $text   = self::bricks_element('t1', 'text', ['text' => '<p>Old</p>', '_attributes' => [['id' => 'a2', 'name' => 'data-section', 'value' => 'hero']]]);
        $page   = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash([$code, $text]));
        $this->log_in_without_unfiltered_html();

        // Other values change, the code moves.
        $text['settings']['text'] = '<p>New</p>';
        Bricks_Content::save($page, [$text, $code]);
        $this->assertSame([$text, $code], Bricks_Content::get($page));

        // A copy of a template's stored code into another page.
        $copy = self::factory()->post->create(['post_type' => 'page']);
        Bricks_Content::save($copy, [$code], [$code]);
        $this->assertSame([$code], Bricks_Content::get($copy));
    }

    public function test_a_bricks_script_stored_as_text_is_not_code_the_page_holds(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash([self::bricks_element('t1', 'text', ['text' => self::SCRIPT])]));
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Bricks_Content::save($page, [self::bricks_element('t1', 'code', ['executeCode' => true, 'javascriptCode' => self::SCRIPT])]));
    }

    /**
     * @dataProvider bricks_code
     * @param array<string, mixed> $element
     */
    public function test_a_user_with_unfiltered_html_writes_bricks_code(array $element): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Bricks_Content::save($page, [$element]);

        $this->assertSame([$element], Bricks_Content::get($page));
    }

    // ------------------------------------------------------ classic Oxygen

    /** @param array<string, mixed> $options */
    private static function oxygen_node(int $id, string $name, array $options): array
    {
        return ['id' => $id, 'name' => $name, 'options' => ['ct_id' => $id, 'ct_parent' => 0, 'selector' => "{$name}-{$id}-1"] + $options, 'depth' => 1];
    }

    /** @param list<array<string, mixed>> $children */
    private static function oxygen_tree(array $children): string
    {
        return (string) wp_json_encode(['id' => 0, 'name' => 'root', 'depth' => 0, 'children' => $children], JSON_UNESCAPED_UNICODE);
    }

    private function oxygen_page(string $json): int
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, '_' . Oxygen_Classic_Content::JSON_KEY, wp_slash($json));

        return $page;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function oxygen_code(): array
    {
        return [
            'code block script'      => [self::oxygen_node(2, 'ct_code_block', ['original' => ['code-js' => self::SCRIPT]])],
            'code block style'       => [self::oxygen_node(2, 'ct_code_block', ['original' => ['code-css' => 'body{display:none}']])],
            'code block PHP'         => [self::oxygen_node(2, 'ct_code_block', ['original' => ['code-php' => 'Hello']])],
            'element script'         => [self::oxygen_node(2, 'ct_text_block', ['ct_content' => 'Hi', 'original' => ['custom-js' => self::SCRIPT]])],
            'PHP function shortcode' => [self::oxygen_node(2, 'ct_text_block', ['ct_content' => "Mail: [oxygen data='phpfunction' function='get_option' arguments='admin_email']"])],
        ];
    }

    /**
     * @dataProvider oxygen_code
     * @param array<string, mixed> $node
     */
    public function test_new_code_in_a_classic_oxygen_tree_is_refused(array $node): void
    {
        $old  = self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => 'Old'])]);
        $page = $this->oxygen_page($old);
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Oxygen_Classic_Content::save($page, self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => 'Old']), $node]), null));

        $this->assertSame($old, Oxygen_Classic_Content::get_json($page));
    }

    public function test_classic_oxygen_code_the_page_holds_is_kept(): void
    {
        $code   = self::oxygen_node(2, 'ct_code_block', ['original' => ['code-js' => self::SCRIPT]]);
        $signed = "[oxygen data='phpfunction' function='get_option' arguments='admin_email' ct_sign_sha256='abc123']";
        $page   = $this->oxygen_page(self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => "Old {$signed}"]), $code]));
        $this->log_in_without_unfiltered_html();

        $new = self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => "New {$signed}"]), $code]);
        Oxygen_Classic_Content::save($page, $new, null);

        $this->assertSame($new, Oxygen_Classic_Content::get_json($page));
    }

    /**
     * @dataProvider oxygen_code
     * @param array<string, mixed> $node
     */
    public function test_a_user_with_unfiltered_html_writes_classic_oxygen_code(array $node): void
    {
        $page = $this->oxygen_page(self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => 'Old'])]));
        $this->log_in_with_unfiltered_html();

        $new = self::oxygen_tree([self::oxygen_node(1, 'ct_text_block', ['ct_content' => 'Old']), $node]);
        Oxygen_Classic_Content::save($page, $new, null);

        $this->assertSame($new, Oxygen_Classic_Content::get_json($page));
    }

    // ------------------------------------------------------ Beaver Builder

    /** @return array<string, object> */
    private static function beaver_nodes(): array
    {
        return [
            'r1' => (object) ['node' => 'r1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => (object) []],
            'm1' => (object) ['node' => 'm1', 'type' => 'module', 'parent' => 'r1', 'position' => 0, 'settings' => (object) ['type' => 'rich-text', 'text' => '<p>Hi</p>']],
        ];
    }

    /** @return array<string, array{0: object}> */
    public static function beaver_code(): array
    {
        $module = static fn (array $settings): object => (object) ['node' => 'm2', 'type' => 'module', 'parent' => 'r1', 'position' => 1, 'settings' => (object) $settings];

        return [
            'node script'             => [$module(['type' => 'rich-text', 'text' => 'Hi', 'bb_js_code' => self::SCRIPT])],
            'button click handler'    => [$module(['type' => 'button', 'text' => 'Go', 'click_action' => 'button', 'button' => self::SCRIPT])],
            'button group handler'    => [$module(['type' => 'button-group', 'items' => [(object) ['text' => 'Go', 'click_action' => 'button', 'button' => self::SCRIPT]]])],
            'box script target'       => [$module(['type' => 'box', 'link_target' => "_blank';" . self::SCRIPT . '//'])],
            'event handler attribute' => [$module(['type' => 'rich-text', 'text' => 'Hi', 'custom_attributes' => [(object) ['key' => 'onclick', 'value' => self::SCRIPT, 'target' => 'wrapper']]])],
            'photo attribute'         => [$module(['type' => 'photo', 'attributes' => ['onload' => self::SCRIPT]])],
            'photo lightbox url'      => [$module(['type' => 'photo', 'data' => (object) ['alt' => "Jo's photo", 'sizes' => (object) ['full' => (object) ['url' => "x.jpg';" . self::SCRIPT . '//']]]])],
            'script container'        => [$module(['type' => 'rich-text', 'text' => 'alert(1)', 'container_element' => 'script'])],
            'unescaped attribute'     => [$module(['type' => 'rich-text', 'text' => 'Hi', 'responsive_display' => 'desktop" onmouseover="' . self::SCRIPT])],
        ];
    }

    /** @dataProvider beaver_code */
    public function test_new_code_in_beaver_builder_nodes_is_refused(object $node): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        Beaver_Builder_Content::save($page, self::beaver_nodes());
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Beaver_Builder_Content::save($page, self::beaver_nodes() + ['m2' => $node]));

        $this->assertEquals(self::beaver_nodes(), Beaver_Builder_Content::get_nodes($page));
    }

    /** @dataProvider beaver_code */
    public function test_a_user_with_unfiltered_html_writes_beaver_builder_node_code(object $node): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Beaver_Builder_Content::save($page, self::beaver_nodes() + ['m2' => $node]);

        $this->assertEquals(self::beaver_nodes() + ['m2' => $node], Beaver_Builder_Content::get_nodes($page));
    }

    public function test_new_beaver_builder_layout_script_is_refused(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        Beaver_Builder_Content::save($page, self::beaver_nodes());
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Beaver_Builder_Content::save_layout($page, self::beaver_nodes(), (object) ['css' => '', 'js' => self::SCRIPT]));
        $this->assert_code_refused(fn () => Beaver_Builder_Content::merge_settings($page, (object) ['css' => '', 'js' => self::SCRIPT]));

        $this->assertNull(Beaver_Builder_Content::get_settings($page));
    }

    public function test_beaver_builder_layout_script_the_page_or_the_template_holds_is_kept(): void
    {
        $page     = self::factory()->post->create(['post_type' => 'page']);
        $settings = (object) ['css' => '.a{color:red}', 'js' => self::SCRIPT];
        $this->log_in_with_unfiltered_html();
        Beaver_Builder_Content::save_layout($page, self::beaver_nodes(), $settings);
        $this->log_in_without_unfiltered_html();

        // The layout put back as stored, then a template's stored script merged in.
        Beaver_Builder_Content::save_layout($page, self::beaver_nodes(), $settings);
        $template = (object) ['css' => '', 'js' => 'console.log("template");'];
        Beaver_Builder_Content::merge_settings($page, $template, [$template]);

        $this->assertStringContainsString('console.log("template");', (string) Beaver_Builder_Content::get_settings($page)->js);
    }

    public function test_a_user_with_unfiltered_html_writes_a_beaver_builder_layout_script(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Beaver_Builder_Content::save_layout($page, self::beaver_nodes(), (object) ['css' => '', 'js' => self::SCRIPT]);

        $this->assertSame(self::SCRIPT, Beaver_Builder_Content::get_settings($page)->js);
    }

    // ------------------------------------------- Breakdance and Oxygen 6

    /** @param array<string, mixed> $spec */
    private static function engine_doc(array ...$specs): object
    {
        $doc = Breakdance_Tree::blank();
        foreach ($specs as $spec) {
            [$doc] = Breakdance_Tree::add($doc, '', null, $spec);
        }

        return $doc;
    }

    /** @return array<string, mixed> */
    private static function engine_text(string $text): array
    {
        return ['type' => 'EssentialElements\\Text', 'properties' => ['content' => ['content' => ['text' => $text]]]];
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function engine_code(): array
    {
        $settings = static fn (array $settings): array => ['type' => 'EssentialElements\\Text', 'properties' => ['content' => ['content' => ['text' => 'Hi']], 'settings' => $settings]];

        return [
            'Oxygen JavaScript Code'   => ['oxygen', ['type' => 'OxygenElements\\JavaScriptCode', 'properties' => ['content' => ['content' => ['javascript_code' => self::SCRIPT]]]]],
            'Oxygen CSS Code'          => ['oxygen', ['type' => 'OxygenElements\\CssCode', 'properties' => ['content' => ['content' => ['css_code' => 'body{display:none}']]]]],
            'Oxygen PHP Code'          => ['oxygen', ['type' => 'OxygenElements\\PhpCode', 'properties' => ['content' => ['content' => ['php_code' => 'Hello']]]]],
            'PHP loop query'           => ['oxygen', ['type' => 'OxygenElements\\PostsLoop', 'properties' => ['content' => ['query' => ['query' => ['active' => 'php', 'php' => 'return ["post_type" => "page"];']]]]]],
            'term query'               => ['oxygen', ['type' => 'OxygenElements\\TermLoopBuilder', 'properties' => ['content' => ['query' => ['load_terms_by_query' => true, 'term_query' => 'return [];']]]]],
            'PHP display condition'    => ['breakdance', $settings(['conditions' => ['conditions' => [[['ruleSlug' => 'custom-php', 'value' => 'return true;']]]]])],
            'PHP dynamic data'         => ['breakdance', self::engine_text('Hi [breakdance_dynamic field="phpreturn" code="return get_option(\'admin_email\');"]')],
            'script interaction'       => ['oxygen', $settings(['interactions' => ['interactions' => [['actions' => [['name' => 'javascript_function', 'js_function_name' => 'eval']]]]]])],
            'event handler attribute'  => ['breakdance', $settings(['advanced' => ['attributes' => [['name' => 'onclick', 'value' => self::SCRIPT]]]])],
            'script tag'               => ['oxygen', $settings(['advanced' => ['tag' => 'script']])],
        ];
    }

    /**
     * @dataProvider engine_code
     * @param array<string, mixed> $spec
     */
    public function test_new_code_in_a_breakdance_engine_document_is_refused(string $builder, array $spec): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        Breakdance_Content::save($page, self::engine_doc(self::engine_text('<p>Old</p>')), $builder);
        $before = get_post_meta($page, Breakdance_Content::data_key($builder, $page), true);
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Breakdance_Content::save($page, self::engine_doc(self::engine_text('<p>Old</p>'), $spec), $builder));

        $this->assertSame($before, get_post_meta($page, Breakdance_Content::data_key($builder, $page), true));
    }

    public function test_breakdance_engine_code_the_page_or_the_copied_source_holds_is_kept(): void
    {
        $code = ['type' => 'OxygenElements\\JavaScriptCode', 'properties' => ['content' => ['content' => ['javascript_code' => self::SCRIPT]]]];
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();
        Breakdance_Content::save($page, self::engine_doc($code, self::engine_text('<p>Old</p>')), 'oxygen');
        $this->log_in_without_unfiltered_html();

        $new = self::engine_doc($code, self::engine_text('<p>New</p>'));
        Breakdance_Content::save($page, $new, 'oxygen');
        $this->assertEquals($new, Breakdance_Content::get_document($page, 'oxygen'));

        $copy = self::factory()->post->create(['post_type' => 'page']);
        Breakdance_Content::save($copy, $new, 'oxygen', $new);
        $this->assertEquals($new, Breakdance_Content::get_document($copy, 'oxygen'));
    }

    /**
     * @dataProvider engine_code
     * @param array<string, mixed> $spec
     */
    public function test_a_user_with_unfiltered_html_writes_breakdance_engine_code(string $builder, array $spec): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Breakdance_Content::save($page, self::engine_doc($spec), $builder);

        $this->assertEquals(self::engine_doc($spec), Breakdance_Content::get_document($page, $builder));
    }

    // -------------------------------------------------------------- Divi

    private static function divi5(string $blocks): string
    {
        return '<!-- wp:divi/placeholder -->' . $blocks . '<!-- /wp:divi/placeholder -->';
    }

    /** @return array<string, array{0: string}> */
    public static function divi_code(): array
    {
        return [
            'Divi 5 code module'       => [self::divi5('<!-- wp:divi/code {"content":{"innerContent":{"desktop":{"value":"\u003cscript\u003edocument.title = 1;\u003c/script\u003e"}}}} /-->')],
            'Divi 5 escaped script'    => [self::divi5('<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<script>x()</script>"}}}} /-->')],
            'Divi 5 attribute'         => [self::divi5('<!-- wp:divi/text {"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"name":"onclick","value":"x()"}]}}}}}} /-->')],
            'Divi 5 script tag'        => [self::divi5('<!-- wp:divi/text {"module":{"advanced":{"html":{"desktop":{"value":{"elementType":"script"}}}}}} /-->')],
            'Divi 5 attribute effect'  => [self::divi5('<!-- wp:divi/text {"module":{"decoration":{"interactions":{"desktop":{"value":{"interactions":[{"effect":"addAttribute","attributeName":"onmouseover","attributeValue":"x()"}]}}}}}} /-->')],
            'Divi 4 encoded code'      => ['[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_code _builder_version="3.0"]&lt;script&gt;x()&lt;/script&gt;[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]'],
        ];
    }

    /** @dataProvider divi_code */
    public function test_markup_a_divi_layout_hides_from_kses_is_refused(string $content): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Divi_Content::save($page, $content));

        $this->assertSame('', get_post($page)->post_content);
    }

    public function test_divi_code_the_page_holds_is_kept(): void
    {
        $code = '<!-- wp:divi/code {"content":{"innerContent":{"desktop":{"value":"document.title = 1;"}}}} /-->';
        $page = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $this->log_in_with_unfiltered_html();
        Divi_Content::save($page, self::divi5($code . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Old"}}}} /-->'));
        $this->log_in_without_unfiltered_html();

        $new = self::divi5($code . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"New"}}}} /-->');
        Divi_Content::save($page, $new);

        $this->assertSame($new, get_post($page)->post_content);
    }

    /** @dataProvider divi_code */
    public function test_a_user_with_unfiltered_html_writes_divi_code(string $content): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $this->log_in_with_unfiltered_html();

        Divi_Content::save($page, $content);

        $this->assertSame($content, get_post($page)->post_content);
    }

    // ------------------------------------------------------------- Thrive

    /** @return array<string, array{0: string}> */
    public static function thrive_code(): array
    {
        return [
            'Custom HTML script'     => ['<div class="thrv_wrapper thrv_custom_html_shortcode"><code class="tve_js_placeholder">[tcb-script]' . esc_html(self::SCRIPT) . '[/tcb-script]</code></div>'],
            'external script'        => ['<div class="thrv_wrapper">[tcb-script src="https://example.org/x.js"][/tcb-script]</div>'],
            'WordPress content raw'  => ['<div class="thrv_wrapper tve_wp_shortcode">___TVE_SHORTCODE_RAW__' . esc_html('<img src="x.png" onerror="x()">') . '__TVE_SHORTCODE_RAW___</div>'],
        ];
    }

    private function thrive_page(string $layout): int
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Thrive_Content::LAYOUT_META_KEY, wp_slash($layout));

        return $page;
    }

    /** @dataProvider thrive_code */
    public function test_new_code_in_a_thrive_layout_is_refused(string $layout): void
    {
        $page = $this->thrive_page('<div class="thrv_wrapper thrv_text_element"><p>Old</p></div>');
        $this->log_in_without_unfiltered_html();

        $this->assert_code_refused(fn () => Thrive_Content::save($page, '<div class="thrv_wrapper thrv_text_element"><p>Old</p></div>' . $layout, null));

        $this->assertSame('<div class="thrv_wrapper thrv_text_element"><p>Old</p></div>', Thrive_Content::get_content($page));
    }

    /** @dataProvider thrive_code */
    public function test_a_user_with_unfiltered_html_writes_thrive_code(string $layout): void
    {
        $page = $this->thrive_page('');
        $this->log_in_with_unfiltered_html();

        Thrive_Content::save($page, $layout, null);

        $this->assertSame($layout, Thrive_Content::get_content($page));
    }
}
