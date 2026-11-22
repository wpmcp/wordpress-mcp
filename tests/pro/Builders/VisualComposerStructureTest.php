<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content, Visual_Composer_Markup_Parser};

/**
 * Issue #1112: Visual Composer structural writes (move, remove, and a blank
 * page filled from a saved template) through update-builder-content.
 *
 * Visual Composer is not loaded here. A page is stored the way its editor's
 * save stores one (Visual Composer 45.16.4, public/dist/wp.bundle.js: the
 * request's vcv-content is "<!--vcv no format-->" + normalizeHtml(innerHTML
 * of the content layout) + "<!--vcv no format-->"; vcv-data is the
 * document). Each element's outermost node (where the editor props go) is a
 * direct child of its parent's data-vce-element-content node, or of the
 * page at the top; its id="el-<id>" node is that node or inside it (row:
 * vce-row-container > vce-row#el; column: vce-col#el; Text Block:
 * vce-text-block > vce-text-block-wrapper#el; Basic Button:
 * vce-button--style-basic-container > span#el). Where an element may go is
 * Visual Composer's own rule, read from the element bundles (relatedTo,
 * containerFor, parentWrapper; the page root takes RootElements), recorded
 * under tests/support/visual-composer/structure-elements.
 */
class VisualComposerStructureTest extends \WP_UnitTestCase
{
    private const R1 = 'r1aaaaa';
    private const C1 = 'c1aaaaa';
    private const B1 = 'b1aaaaa';
    private const T1 = 't1aaaaa';
    private const R2 = 'r2aaaaa';
    private const C2 = 'c2aaaaa';
    private const T2 = 't2aaaaa';

    private const T2_HTML = '<p>Two &gt; one</p><img src="x.png" alt="a > b"><script>if (a < b) { x = "</div>"; }</script>';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_visual_composer_active', '__return_true');
        add_filter('wpmcp_visual_composer_element_dirs', static fn () => [dirname(__DIR__, 2) . '/support/visual-composer/structure-elements']);
        if (! post_type_exists('vcv_templates')) {
            register_post_type('vcv_templates', ['public' => false]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_visual_composer_active');
        remove_all_filters('wpmcp_visual_composer_element_dirs');
        parent::tearDown();
    }

    public static function row(string $id, string $inner): string
    {
        return '<div class="vce-row-container"><div class="vce-row vce-row--col-gap-30 vce-row-columns--top" id="el-' . $id . '" data-vce-do-apply="all el-' . $id . '"><div class="vce-row-content" data-vce-element-content="true">' . $inner . '</div></div></div>';
    }

    public static function col(string $id, string $inner): string
    {
        return '<div class="vce-col vce-col--md-auto vce-col--xs-1" id="el-' . $id . '"><div class="vce-col-inner" data-vce-do-apply="border margin background el-' . $id . '"><div class="vce-col-content" data-vce-element-content="true" data-vce-do-apply="padding el-' . $id . '">' . $inner . '</div></div></div>';
    }

    public static function button(string $id, string $text): string
    {
        return '<div class="vce-button--style-basic-container"><span class="vce-button--style-basic-wrapper vce" id="el-' . $id . '" data-vce-do-apply="margin el-' . $id . '"><a class="vce-button vce-button--style-basic" href="#">' . $text . '</a></span></div>';
    }

    public static function text(string $id, string $html): string
    {
        return '<div class="vce-text-block"><div class="vce-text-block-wrapper vce" id="el-' . $id . '" data-vce-do-apply="all el-' . $id . '">' . $html . '</div></div>';
    }

    public static function saved(string $inner): string
    {
        return '<!--vcv no format-->' . $inner . '<!--vcv no format-->';
    }

    /** @return array<string,mixed> */
    public static function document(): array
    {
        return [
            'elements' => [
                self::R1 => ['tag' => 'row', 'parent' => false, 'id' => self::R1, 'order' => 0, 'metaElementAssets' => new \stdClass(), 'layout' => ['layoutData' => ['auto']]],
                self::C1 => ['tag' => 'column', 'parent' => self::R1, 'id' => self::C1, 'order' => 0, 'size' => ['all' => 'auto']],
                self::B1 => ['tag' => 'basicButton', 'parent' => self::C1, 'id' => self::B1, 'order' => 0, 'buttonText' => 'Apply now'],
                self::T1 => ['tag' => 'textBlock', 'parent' => self::C1, 'id' => self::T1, 'order' => 1, 'output' => '<p>One</p>'],
                self::R2 => ['tag' => 'row', 'parent' => false, 'id' => self::R2, 'order' => 1, 'layout' => ['layoutData' => ['auto']]],
                self::C2 => ['tag' => 'column', 'parent' => self::R2, 'id' => self::C2, 'order' => 0, 'size' => ['all' => 'auto']],
                self::T2 => ['tag' => 'textBlock', 'parent' => self::C2, 'id' => self::T2, 'order' => 0, 'output' => self::T2_HTML],
            ],
        ];
    }

    public static function html(): string
    {
        return self::saved(
            self::row(self::R1, self::col(self::C1, self::button(self::B1, 'Apply now') . self::text(self::T1, '<p>One</p>')))
            . self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))
        );
    }

    private function page(array $args = [], ?array $doc = null, ?string $html = null): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_status' => 'publish', 'post_content' => wp_slash($html ?? self::html())]);
        update_post_meta($id, 'vcv-pageContent', rawurlencode((string) wp_json_encode($doc ?? self::document())));
        update_post_meta($id, 'vcv-be-editor', 'fe');
        update_post_meta($id, 'vcvSourceCss', '#el-' . self::T1 . '{color:red}');

        return $id;
    }

    private function hash(int $id): string
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return (string) $out['hash'];
    }

    private function write(int $id, array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'visual-composer', 'expected_hash' => $this->hash($id)]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function stored(int $id): array
    {
        return json_decode(rawurldecode((string) get_post_meta($id, 'vcv-pageContent', true)), true)['elements'];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function state(int $id): array
    {
        clean_post_cache($id);

        return [get_post_meta($id), (string) get_post($id)->post_content];
    }

    private function refused(int $id, array $args, string $code, string $message = ''): void
    {
        $before  = $this->state($id);
        $session = wp_generate_uuid4();
        $out     = $this->write($id, $args + ['session_id' => $session]);
        $this->assertWPError($out, $message);
        $this->assertSame($code, $out->get_error_code(), $message . ': ' . $out->get_error_message());
        $this->assertSame($before, $this->state($id), $message . ': nothing written');
        $this->assertSame(0, Snapshot_Store::live_count_for_session($session), $message . ': no snapshot taken');
    }

    public function test_parser_finds_each_elements_outer_node_and_content_node(): void
    {
        $markup = (new Visual_Composer_Markup_Parser())->parse(self::html());
        $this->assertSame([self::R1, self::R2], array_map(static fn ($n) => $markup->element_id($n), $markup->root_children()));

        $text = $markup->outer(self::T2);
        $this->assertNotNull($text);
        $this->assertSame(self::text(self::T2, self::T2_HTML), $markup->slice($text));

        $content = $markup->content(self::C1);
        $this->assertNotNull($content);
        $this->assertSame([self::B1, self::T1], array_map(static fn ($n) => $markup->element_id($n), $content->children));

        $this->assertNull($markup->content(self::T1), 'a Text Block holds no elements');
    }

    public function test_parser_refuses_markup_it_cannot_balance(): void
    {
        $this->expectException(\DomainException::class);
        (new Visual_Composer_Markup_Parser())->parse(self::saved('<div class="vce-row-container"><div id="el-x"></span></div>'));
    }

    public function test_remove_takes_the_element_out_of_both_stores_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        $before  = $this->state($id);
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, ['operation' => 'remove', 'path' => self::B1, 'session_id' => $session]));
        $this->assertSame($this->hash($id), $out['hash']);

        $elements = $this->stored($id);
        $this->assertArrayNotHasKey(self::B1, $elements);
        $this->assertSame(0, $elements[ self::T1 ]['order']);
        $this->assertEquals(new \stdClass(), json_decode(rawurldecode((string) get_post_meta($id, 'vcv-pageContent', true)))->elements->{self::R1}->metaElementAssets);
        $this->assertSame(
            self::saved(self::row(self::R1, self::col(self::C1, self::text(self::T1, '<p>One</p>'))) . self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))),
            get_post($id)->post_content
        );
        $this->assertSame('visual-composer', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $this->assertSame(1, Snapshot_Store::live_count_for_session($session));

        Rollback_Service::restore_session($session);
        $this->assertSame($before, $this->state($id));
    }

    public function test_remove_a_row_takes_its_columns_and_their_elements(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'remove', 'path' => self::R1]));

        $elements = $this->stored($id);
        $this->assertSame([self::R2, self::C2, self::T2], array_keys($elements));
        $this->assertSame(0, $elements[ self::R2 ]['order']);
        $this->assertSame(self::saved(self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))), get_post($id)->post_content);
    }

    public function test_move_into_another_column_splices_the_rendered_markup_and_rolls_back(): void
    {
        $id      = $this->page();
        $before  = $this->state($id);
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, ['operation' => 'move', 'path' => self::T1, 'to' => self::C2, 'index' => 0, 'session_id' => $session]));
        $this->assertSame(self::T1, $out['path']);

        $elements = $this->stored($id);
        $this->assertSame(self::C2, $elements[ self::T1 ]['parent']);
        $this->assertSame(0, $elements[ self::T1 ]['order']);
        $this->assertSame(1, $elements[ self::T2 ]['order']);
        $this->assertSame(0, $elements[ self::B1 ]['order']);
        $this->assertSame(
            self::saved(
                self::row(self::R1, self::col(self::C1, self::button(self::B1, 'Apply now')))
                . self::row(self::R2, self::col(self::C2, self::text(self::T1, '<p>One</p>') . self::text(self::T2, self::T2_HTML)))
            ),
            get_post($id)->post_content
        );
        $this->assertSame('#el-' . self::T1 . '{color:red}', get_post_meta($id, 'vcvSourceCss', true), 'its compiled CSS is keyed by id and still applies');

        Rollback_Service::restore_session($session);
        $this->assertSame($before, $this->state($id));
    }

    public function test_move_reorders_within_a_column_and_rows_at_the_top(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'move', 'path' => self::B1, 'to' => self::C1]));
        $elements = $this->stored($id);
        $this->assertSame(0, $elements[ self::T1 ]['order']);
        $this->assertSame(1, $elements[ self::B1 ]['order']);

        $this->ok($this->write($id, ['operation' => 'move', 'path' => self::R2, 'to' => '', 'index' => 0]));
        $elements = $this->stored($id);
        $this->assertSame(0, $elements[ self::R2 ]['order']);
        $this->assertSame(1, $elements[ self::R1 ]['order']);
        $this->assertSame(
            self::saved(
                self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))
                . self::row(self::R1, self::col(self::C1, self::text(self::T1, '<p>One</p>') . self::button(self::B1, 'Apply now')))
            ),
            get_post($id)->post_content
        );
    }

    public function test_a_row_may_move_into_a_column_as_visual_composer_allows(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'move', 'path' => self::R2, 'to' => self::C1, 'index' => 2]));
        $elements = $this->stored($id);
        $this->assertSame(self::C1, $elements[ self::R2 ]['parent']);
        $this->assertSame(2, $elements[ self::R2 ]['order']);
        $this->assertSame(
            self::saved(self::row(self::R1, self::col(self::C1, self::button(self::B1, 'Apply now') . self::text(self::T1, '<p>One</p>') . self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))))),
            get_post($id)->post_content
        );
    }

    public function test_structure_the_editor_lays_out_is_refused_with_nothing_written(): void
    {
        $id = $this->page();
        $this->refused($id, ['operation' => 'move', 'path' => self::C1, 'to' => self::R2], 'editor_render_required', 'a column (the row lays out its columns)');
        $this->refused($id, ['operation' => 'remove', 'path' => self::C2], 'editor_render_required', 'removing the element "column"');
        $this->refused($id, ['operation' => 'move', 'path' => self::T1, 'to' => ''], 'editor_render_required', 'a Text Block at the top (the editor wraps it in a row)');
        $this->refused($id, ['operation' => 'add', 'to' => self::C1, 'element' => ['tag' => 'textBlock', 'output' => '<p>x</p>']], 'editor_render_required', 'a new element');
        $this->refused($id, ['operation' => 'add', 'to' => self::C1, 'element' => ['template' => 1]], 'editor_render_required', 'a template into a page that has a layout');
    }

    public function test_bad_targets_are_refused_as_requests(): void
    {
        $id = $this->page();
        $this->refused($id, ['operation' => 'move', 'path' => 'nope123', 'to' => self::C1], 'invalid_visual_composer_request', 'unknown element');
        $this->refused($id, ['operation' => 'remove', 'path' => ''], 'invalid_visual_composer_request', 'no path');
        $this->refused($id, ['operation' => 'move', 'path' => self::T1, 'to' => 'nope123'], 'invalid_visual_composer_request', 'unknown target');
        $this->refused($id, ['operation' => 'move', 'path' => self::T1, 'to' => self::B1], 'invalid_visual_composer_request', 'a button holds no elements');
        $this->refused($id, ['operation' => 'move', 'path' => self::R1, 'to' => self::C1], 'invalid_visual_composer_request', 'into itself');
        $this->refused($id, ['operation' => 'move', 'path' => self::T1, 'to' => self::C2, 'index' => 5], 'invalid_visual_composer_request', 'index past the end');
    }

    public function test_markup_out_of_step_with_the_document_is_refused(): void
    {
        // The saved markup lacks the button the document lists.
        $drift = $this->page([], null, self::saved(self::row(self::R1, self::col(self::C1, self::text(self::T1, '<p>One</p>'))) . self::row(self::R2, self::col(self::C2, self::text(self::T2, self::T2_HTML)))));
        $this->refused($drift, ['operation' => 'remove', 'path' => self::T1], 'editor_render_required', 'drifted column');
        $this->refused($drift, ['operation' => 'move', 'path' => self::T2, 'to' => self::C1], 'editor_render_required', 'drifted target');

        // An element whose bundle is not installed: its rules are unknown.
        $doc = self::document();
        $doc['elements'][ self::B1 ]['tag'] = 'premiumThing';
        $unknown = $this->page([], $doc);
        $this->refused($unknown, ['operation' => 'move', 'path' => self::B1, 'to' => self::C2], 'editor_render_required', 'unknown element type');
    }

    public function test_writes_need_the_plugin_and_the_hash(): void
    {
        $id = $this->page();
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'remove', 'path' => self::B1]);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'move', 'path' => self::B1, 'to' => self::C2, 'expected_hash' => str_repeat('0', 64)]);
        $this->assertSame('stale_expected_hash', $out->get_error_code());

        $hash = $this->hash($id);
        remove_all_filters('wpmcp_visual_composer_active');
        add_filter('wpmcp_visual_composer_active', '__return_false');
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'remove', 'path' => self::B1, 'expected_hash' => $hash]);
        $this->assertSame('plugin_missing', $out->get_error_code());

        $other = self::factory()->post->create(['post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
        add_filter('wpmcp_visual_composer_active', '__return_true', 20);
        $out = (new Update_Builder_Content())->handle(['post_id' => $other, 'builder' => 'visual-composer', 'operation' => 'remove', 'path' => self::B1, 'expected_hash' => 'x']);
        $this->assertSame('unsupported_builder', $out->get_error_code());
    }

    private function template(?string $html = null, bool $css = true, string $status = 'publish', int $author = 0): int
    {
        $tpl = self::factory()->post->create(['post_type' => 'vcv_templates', 'post_title' => 'Hero', 'post_status' => $status, 'post_author' => $author ?: $this->admin, 'post_content' => wp_slash($html ?? self::html())]);
        update_post_meta($tpl, 'vcv-pageContent', rawurlencode((string) wp_json_encode(self::document())));
        update_post_meta($tpl, '_vcv-type', 'custom');
        update_post_meta($tpl, 'vcvSourceCss', wp_slash('#el-' . self::T1 . '{color:"red"}'));
        update_post_meta($tpl, 'vcvSourceAssetsFiles', ['cssBundles' => [], 'jsBundles' => []]);
        update_post_meta($tpl, 'vcvSettingsSourceCustomCss', '');
        update_post_meta($tpl, '_vcv-pageDesignOptionsData', '');
        if ($css) {
            update_post_meta($tpl, 'vcv-globalElementsCssData', wp_slash(['row' => ['baseCss' => '.vce-row{display:flex}', 'mixinsCss' => '', 'attributesCss' => '']]));
        }
        update_post_meta($tpl, '_vcv-globalCssMigrated', 1);
        update_post_meta($tpl, 'vcvSourceCssFileUrl', '/assets-bundles/abc.source.css');

        return $tpl;
    }

    public function test_a_blank_page_is_filled_from_a_saved_template_and_rolls_back_to_blank(): void
    {
        $tpl     = $this->template();
        $blank   = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_content' => '']);
        update_post_meta($blank, 'vcvSourceCssFileUrl', '/assets-bundles/old.source.css');
        update_post_meta($blank, '_vcv-globalCssMigrated', 1);
        $before  = $this->state($blank);
        $session = wp_generate_uuid4();

        $out = $this->ok((new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'visual-composer', 'operation' => 'add', 'element' => ['template' => $tpl], 'session_id' => $session]));
        $this->assertSame($this->hash($blank), $out['hash']);

        clean_post_cache($blank);
        $this->assertSame(self::html(), get_post($blank)->post_content);
        $this->assertSame(array_keys(self::document()['elements']), array_keys($this->stored($blank)));
        $this->assertSame('fe', get_post_meta($blank, 'vcv-be-editor', true));
        $this->assertSame('#el-' . self::T1 . '{color:"red"}', get_post_meta($blank, 'vcvSourceCss', true));
        $this->assertSame(['row' => ['baseCss' => '.vce-row{display:flex}', 'mixinsCss' => '', 'attributesCss' => '']], get_post_meta($blank, 'vcv-globalElementsCssData', true));
        $this->assertSame(['cssBundles' => [], 'jsBundles' => []], get_post_meta($blank, 'vcvSourceAssetsFiles', true));
        // Visual Composer builds the page's CSS file from these on its first view.
        $this->assertSame('', get_post_meta($blank, '_vcv-globalCssMigrated', true));
        $this->assertSame('', get_post_meta($blank, 'vcvSourceCssFileUrl', true));
        $this->assertSame('', get_post_meta($blank, '_vcv-type', true), 'the template type stays on the template');
        $this->assertSame('visual-composer', (new Detect_Builder())->handle(['post_id' => $blank])['builder']);
        $this->assertSame('draft', get_post_status($blank));

        Rollback_Service::restore_session($session);
        $this->assertSame($before, $this->state($blank));
    }

    public function test_templates_that_cannot_be_copied_whole_are_refused(): void
    {
        $blank = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $call  = function (array $element, string $code, string $message) use ($blank): void {
            $before  = $this->state($blank);
            $session = wp_generate_uuid4();
            $out     = (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'visual-composer', 'operation' => 'add', 'element' => $element, 'session_id' => $session]);
            $this->assertWPError($out, $message);
            $this->assertSame($code, $out->get_error_code(), $message . ': ' . $out->get_error_message());
            $this->assertSame($before, $this->state($blank), $message);
            $this->assertSame(0, Snapshot_Store::live_count_for_session($session), $message);
        };

        // A template stored as a document only (no rendered markup), as the downloaded ones are.
        $call(['template' => $this->template('')], 'editor_render_required', 'no markup');
        $call(['template' => $this->template(self::saved(self::row(self::R1, '')))], 'editor_render_required', 'markup out of step');
        $call(['template' => $this->template(null, false)], 'editor_render_required', 'no element CSS');
        $call(['template' => self::factory()->post->create()], 'invalid_visual_composer_request', 'not a template');
        $call(['template' => 0], 'invalid_visual_composer_request', 'no template id');
        $call(['tag' => 'textBlock'], 'editor_render_required', 'a new element');

        $private = $this->template(null, true, 'private', self::factory()->user->create(['role' => 'editor']));
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $call(['template' => $private], 'forbidden', 'a template the caller cannot read');
        wp_set_current_user($this->admin);

        $classic = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>Written by hand</p>']);
        $out     = (new Update_Builder_Content())->handle(['post_id' => $classic, 'builder' => 'visual-composer', 'operation' => 'add', 'element' => ['template' => $this->template()]]);
        $this->assertSame('unsupported_builder', $out->get_error_code(), 'a page with other content is not blank');
        $this->assertSame('<p>Written by hand</p>', get_post($classic)->post_content);

        remove_all_filters('wpmcp_visual_composer_active');
        add_filter('wpmcp_visual_composer_active', '__return_false');
        $call(['template' => $this->template()], 'plugin_missing', 'plugin not loaded');
    }
}
