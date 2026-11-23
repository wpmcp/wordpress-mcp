<?php

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Blocks\Update_Block;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Export\Export_Dir;
use WPMCP\Tools\Export\Import_Content;
use WPMCP\Tools\Update_Blocks;

/**
 * Issue #1243, the core content tools, for a user without unfiltered_html
 * (a site administrator on a network). Plain classic and block content is
 * sanitized by WordPress as its editor does it (the #440 decision), and the
 * write now says so: the response carries sanitized:true and a short list
 * of what kses removed. A surgical block edit, which must keep every other
 * block byte for byte, refuses with unfiltered_html_required instead. The
 * mirror restore, stage publish and find-replace paths are covered in
 * tests/free/Safety/ContentWriteFilterTest.
 */
class ContentToolsKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        remove_all_filters('wpmcp_enable_import');
        parent::tearDown();
    }

    /** Run a write that must be refused and return the refusal. */
    private function refusal(callable $write): \WP_Error
    {
        try {
            $write();
        } catch (Post_Write_Refused $e) {
            return $e->error();
        }
        $this->fail('The write kses would rewrite was not refused.');
    }

    private function page_with_stored_markup(): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->store_raw($id, Kses_Markup::STORED);

        return $id;
    }

    /** @param array<string, mixed> $out */
    private function assert_sanitized(array $out, string $removed = '<script> element'): void
    {
        $this->assertTrue($out['sanitized'] ?? false, (string) wp_json_encode($out));
        $this->assertContains($removed, $out['removed']);
    }

    private function page_count(): int
    {
        return count(get_posts(['post_type' => ['page', 'post'], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    }

    public function test_update_post_reports_what_kses_removed_from_new_content(): void
    {
        $id = $this->page_with_stored_markup();
        $this->log_in_without_unfiltered_html();

        $out = (new Update_Post())->handle(['post_id' => $id, 'content' => Kses_Markup::UNSAFE]);

        $this->assert_sanitized($out);
        $this->assertSame(wp_kses_post(Kses_Markup::UNSAFE), get_post($id)->post_content);
    }

    public function test_update_post_reports_stored_markup_kses_strips_on_a_resave(): void
    {
        $id = $this->page_with_stored_markup();
        $this->log_in_without_unfiltered_html();

        $out = (new Update_Post())->handle(['post_id' => $id, 'content' => Kses_Markup::STORED, 'title' => 'Renamed']);

        $this->assert_sanitized($out);
        $this->assertStringNotContainsString('<script', get_post($id)->post_content);
    }

    public function test_update_post_reports_nothing_when_kses_keeps_the_content(): void
    {
        $id = $this->page_with_stored_markup();
        $this->log_in_without_unfiltered_html();

        $out = (new Update_Post())->handle(['post_id' => $id, 'title' => 'Renamed']);
        $this->assertSame(Kses_Markup::STORED, get_post($id)->post_content, 'A title-only edit leaves the content as stored.');
        $this->assertArrayNotHasKey('sanitized', $out);

        // Re-encoding that parses the same ("&" to "&amp;") is not reported.
        $out = (new Update_Post())->handle(['post_id' => $id, 'content' => '<p>Safe <strong>edit</strong> &amp; more & more</p>']);
        $this->assertArrayNotHasKey('sanitized', $out);
    }

    public function test_create_post_reports_what_kses_removed(): void
    {
        $this->log_in_without_unfiltered_html();

        $out = (new Create_Post())->handle(['post_type' => 'page', 'title' => 'New', 'content' => '<p>Hi</p><iframe src="https://example.org/x"></iframe>']);

        $this->assert_sanitized($out, '<iframe> element');
        $this->assertSame('<p>Hi</p>', get_post($out['post_id'])->post_content);
        $this->assertArrayNotHasKey('sanitized', (new Create_Post())->handle(['post_type' => 'page', 'title' => 'New', 'content' => '<p>Kept</p>']));
    }

    public function test_update_blocks_reports_what_kses_removed(): void
    {
        $id = $this->page_with_stored_markup();
        $this->log_in_without_unfiltered_html();

        $out = (new Update_Blocks())->handle(['id' => $id, 'blocks' => "<!-- wp:paragraph -->\n<p><button onclick=\"go()\">Go</button></p>\n<!-- /wp:paragraph -->"]);

        $this->assert_sanitized($out, 'onclick attribute on <button>');
    }

    public function test_a_surgical_block_edit_on_a_page_holding_stored_markup_is_refused(): void
    {
        $content = "<!-- wp:html -->\n" . Kses_Markup::STORED . "\n<!-- /wp:html -->\n\n<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";
        $id      = self::factory()->post->create(['post_type' => 'page']);
        $this->store_raw($id, $content);
        $this->log_in_without_unfiltered_html();

        $error = $this->refusal(fn () => (new Update_Block())->handle([
            'id'            => $id,
            'expected_hash' => hash('sha256', $content),
            'path'          => [2],
            'inner_html'    => "\n<p>World</p>\n",
        ]));

        $this->assert_kses_refused($error);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_duplicating_a_page_holding_stored_markup_reports_what_the_copy_lost(): void
    {
        $id = $this->page_with_stored_markup();
        $this->log_in_without_unfiltered_html();

        $out = (new Duplicate_Post())->handle(['post_id' => $id]);

        $this->assert_sanitized($out);
        $this->assertStringNotContainsString('<script', get_post($out['post_id'])->post_content);
        $this->assertSame(Kses_Markup::STORED, get_post($id)->post_content, 'The source is untouched.');
    }

    public function test_build_page_reports_what_kses_removed_from_an_html_node(): void
    {
        $this->log_in_without_unfiltered_html();
        $spec = ['title' => 'Built', 'content' => [['type' => 'html', 'settings' => ['html' => Kses_Markup::UNSAFE]]]];

        $out = (new Build_Page())->handle(['spec' => $spec]);

        $this->assert_sanitized($out);
    }

    public function test_import_content_reports_what_kses_removed(): void
    {
        $this->log_in_without_unfiltered_html();
        add_filter('wpmcp_enable_import', '__return_true');
        $unsafe = Kses_Markup::UNSAFE;
        $xml    = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Test Site</title>
    <item>
        <title>Clean item</title>
        <content:encoded><![CDATA[<p>Clean</p>]]></content:encoded>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
    </item>
    <item>
        <title>Unsafe item</title>
        <content:encoded><![CDATA[{$unsafe}]]></content:encoded>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
    </item>
</channel>
</rss>
XML;
        Export_Dir::protect(Export_Dir::path());
        $file          = trailingslashit(Export_Dir::path()) . 'wpmcp-kses-1243-' . wp_generate_password(8, false) . '.xml';
        $this->files[] = $file;
        file_put_contents($file, $xml);

        $out = (new Import_Content())->handle(['file' => $file, 'confirm' => true]);

        $this->assertSame(2, $out['imported_count']);
        $this->assert_sanitized($out);
    }
}
