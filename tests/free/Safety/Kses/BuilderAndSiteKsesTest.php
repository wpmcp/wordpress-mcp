<?php

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Integrations\Core_Patterns_Pack;
use WPMCP\Integrations\Operation_Refused;
use WPMCP\Integrations\TablePress_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Builders\Avada_Content;
use WPMCP\Tools\Builders\Divi_Content;
use WPMCP\Tools\Builders\WPBakery_Content;
use WPMCP\Tools\Elementor\Elementor_Page_Data;
use WPMCP\Tools\Media\Update_Media;
use WPMCP\Tools\SiteEditor\Site_Templates_Write;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * Issue #1243, the public builder, site editor and block-markup writers,
 * for a user without unfiltered_html (a site administrator on a network).
 * Builder layouts, template parts, pattern detaching and Elementor trees,
 * where sanitizing corrupts the page or the edit must keep the rest of it
 * byte for byte, refuse with unfiltered_html_required and store nothing.
 * Plain block content (media descriptions, synced patterns, a page a table
 * is placed on) is sanitized as WordPress's editor does it, and the write
 * reports sanitized:true with what kses removed. Change sets are covered
 * in ContentWriteFilterTest.
 */
class BuilderAndSiteKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const THEME = 'twentytwentyfour';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        remove_filter('stylesheet', [$this, 'block_theme_slug']);
        remove_filter('template', [$this, 'block_theme_slug']);
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
        remove_all_filters(Core_Patterns_Pack::ENABLE_FILTER);
        parent::tearDown();
    }

    public function block_theme_slug(): string
    {
        return self::THEME;
    }

    private function use_block_theme(): void
    {
        add_filter('stylesheet', [$this, 'block_theme_slug']);
        add_filter('template', [$this, 'block_theme_slug']);
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
    }

    private function refusal(callable $write): \WP_Error
    {
        try {
            $write();
        } catch (Post_Write_Refused $e) {
            return $e->error();
        } catch (Operation_Refused $e) {
            return new \WP_Error($e->error_code(), $e->getMessage(), $e->error_data());
        }
        $this->fail('The write kses would rewrite was not refused.');
    }

    private function page(string $content): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->store_raw($id, $content);

        return $id;
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function shortcode_builders(): array
    {
        return [
            'divi'     => [Divi_Content::class, '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_code]%s[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]'],
            'wpbakery' => [WPBakery_Content::class, '[vc_row][vc_column][vc_raw_html]%s[/vc_raw_html][/vc_column][/vc_row]'],
            'avada'    => [Avada_Content::class, '[fusion_builder_container][fusion_builder_row][fusion_code]%s[/fusion_code][/fusion_builder_row][/fusion_builder_container]'],
        ];
    }

    /** @dataProvider shortcode_builders */
    public function test_a_builder_layout_kses_would_rewrite_is_refused(string $writer, string $layout): void
    {
        $stored = sprintf($layout, Kses_Markup::STORED);
        $id     = $this->page($stored);
        $this->log_in_without_unfiltered_html();

        // An edit elsewhere in the layout re-saves the stored script too.
        $error = $this->refusal(fn () => $writer::save($id, str_replace('Stored', 'Edited', $stored)));

        $this->assert_kses_refused($error);
        $this->assertStringContainsString(' page holds markup', $error->get_error_message());
        $this->assertSame($stored, get_post($id)->post_content);
    }

    /** @dataProvider shortcode_builders */
    public function test_a_builder_layout_kses_keeps_is_written(string $writer, string $layout): void
    {
        $id = $this->page(sprintf($layout, '<p>Old</p>'));
        $this->log_in_without_unfiltered_html();

        $writer::save($id, sprintf($layout, '<p>New</p>'));

        $this->assertSame(sprintf($layout, '<p>New</p>'), get_post($id)->post_content);
    }

    public function test_a_template_part_kses_would_rewrite_is_refused(): void
    {
        $this->use_block_theme();
        $this->log_in_without_unfiltered_html();
        $write = new Site_Templates_Write();

        $this->assert_kses_refused($this->refusal(fn () => $write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => "<!-- wp:html -->\n" . Kses_Markup::UNSAFE . "\n<!-- /wp:html -->"])));

        $out = $write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => "<!-- wp:paragraph -->\n<p>Footer</p>\n<!-- /wp:paragraph -->"]);
        $this->assertSame("<!-- wp:paragraph -->\n<p>Footer</p>\n<!-- /wp:paragraph -->", get_post((int) $out['wp_id'])->post_content);
    }

    public function test_global_styles_the_save_keeps_are_written(): void
    {
        $this->use_block_theme();
        $this->log_in_without_unfiltered_html();

        $out = (new Site_Templates_Write())->handle(['entity' => 'global_styles', 'attrs' => ['settings.color.palette.theme.base.color' => '#123456']]);

        $this->assertStringContainsString('#123456', get_post((int) $out['user_post_id'])->post_content);
    }

    /** @param array<string, mixed> $out */
    private function assert_sanitized(array $out): void
    {
        $this->assertTrue($out['sanitized'] ?? false, (string) wp_json_encode($out));
        $this->assertContains('<script> element', $out['removed']);
    }

    public function test_a_media_description_kses_rewrites_is_reported(): void
    {
        $id = self::factory()->attachment->create_object('photo.jpg', 0, ['post_mime_type' => 'image/jpeg', 'post_content' => '']);
        $this->log_in_without_unfiltered_html();

        $this->assert_sanitized((new Update_Media())->handle(['media_id' => $id, 'description' => Kses_Markup::UNSAFE]));
        $this->assertSame('<p>Kept</p>alert(1)', get_post($id)->post_content);
        $this->assertArrayNotHasKey('sanitized', (new Update_Media())->handle(['media_id' => $id, 'description' => '<p>A photo</p>']));
    }

    public function test_synced_pattern_writes_kses_rewrites_are_reported(): void
    {
        $this->log_in_without_unfiltered_html();
        add_filter(Core_Patterns_Pack::ENABLE_FILTER, '__return_true');
        $theme = new Theme_Integration();

        $out = $theme->handle_write(['operation' => 'create-synced-pattern', 'args' => ['title' => 'Promo', 'content' => Kses_Markup::UNSAFE]]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assert_sanitized($out['result']);

        $id  = (int) $out['result']['pattern']['id'];
        $out = $theme->handle_write(['operation' => 'update-synced-pattern', 'args' => ['id' => $id, 'content' => Kses_Markup::UNSAFE]]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assert_sanitized($out['result']);
        $this->assertStringNotContainsString('<script', get_post($id)->post_content);
    }

    public function test_detaching_a_pattern_from_a_page_holding_stored_markup_is_refused(): void
    {
        $pattern = self::factory()->post->create(['post_type' => 'wp_block', 'post_title' => 'Promo', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->']);
        $content = '<!-- wp:block {"ref":' . $pattern . '} /-->' . "\n\n<!-- wp:html -->\n" . Kses_Markup::STORED . "\n<!-- /wp:html -->";
        $page    = $this->page($content);
        $this->log_in_without_unfiltered_html();
        add_filter(Core_Patterns_Pack::ENABLE_FILTER, '__return_true');

        $out = (new Theme_Integration())->handle_write(['operation' => 'detach-pattern-instance', 'args' => ['pattern_id' => $pattern, 'post_ids' => [$page]]]);

        $this->assertSame('unfiltered_html_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame($content, get_post($page)->post_content);
    }

    public function test_placing_a_table_on_a_page_holding_stored_markup_reports_what_kses_removed(): void
    {
        $page = $this->page("<!-- wp:html -->\n" . Kses_Markup::STORED . "\n<!-- /wp:html -->");
        $this->log_in_without_unfiltered_html();
        $place = new \ReflectionMethod(TablePress_Pack::class, 'place_table');

        $this->assert_sanitized($place->invoke(null, ['table_id' => '1', 'post_id' => $page]));
        $this->assertStringContainsString('wp:tablepress/table', get_post($page)->post_content);
    }

    public function test_an_elementor_tree_with_a_new_string_kses_would_change_is_refused(): void
    {
        $tree = [['id' => 'a1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => Kses_Markup::STORED], 'elements' => []]];
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, '_elementor_data', wp_slash((string) wp_json_encode($tree)));
        $this->log_in_without_unfiltered_html();

        $added   = $tree;
        $added[] = ['id' => 'b2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => Kses_Markup::UNSAFE], 'elements' => []];
        $this->assert_kses_refused($this->refusal(fn () => Elementor_Page_Data::save($page, $added)));
        $this->assertSame($tree, Elementor_Page_Data::get($page));

        // The stored widget is carried as is; a new string kses keeps is written.
        $added[1]['settings']['html'] = '<p>Plain</p>';
        Elementor_Page_Data::save($page, $added);
        $this->assertSame($added, Elementor_Page_Data::get($page));
    }

    /**
     * Recorded, not guarded: theme builder templates run wp_kses_post over
     * their content for every user before the write (Template_Store), so
     * the save's kses has nothing left to change.
     */
    public function test_theme_builder_templates_are_already_filtered_before_the_save(): void
    {
        $this->log_in_without_unfiltered_html();

        $id = Template_Store::insert('header', 'Header', '<p>Top</p>' . Kses_Markup::UNSAFE, ['include' => [['type' => 'entire_site']], 'exclude' => []], 10);

        $this->assertIsInt($id);
        $this->assertSame(wp_kses_post('<p>Top</p>' . Kses_Markup::UNSAFE), get_post($id)->post_content);
        $this->assertNull((new \WPMCP\Safety\Kses_Safety())->content_refusal($id, Template_Store::sanitize_content(Kses_Markup::UNSAFE), 'template'));
    }
}
