<?php

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Integrations\LMS_LearnDash;
use WPMCP\Integrations\LMS_Thrive_Apprentice;
use WPMCP\Integrations\Popup_Builder_Pack;
use WPMCP\Integrations\Popup_Maker_Pack;
use WPMCP\Integrations\Theme_Layouts_GeneratePress;
use WPMCP\Integrations\Theme_Layouts_Pack;
use WPMCP\Integrations\Thrive_Ovation_Pack;
use WPMCP\Memory\Memory_Store;

/**
 * Issue #1243, the rest of the packs that store post_content (LMS lessons
 * and courses, popups, theme layouts, testimonials), for a user without
 * unfiltered_html (a site administrator on a network): the content is plain
 * post content, sanitized by WordPress as its editor does (#440), and the
 * op's result says what kses removed. Each handler is called directly;
 * store locator stores, MemberPress memberships and the LearnPress and
 * MasterStudy lessons are covered in their pack tests.
 */
class MorePacksKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const CONTEXT = ['tool_name' => 'plugin-data-write', 'operation' => 'x', 'session_id' => 'kses-1243'];

    /**
     * @param array<int, mixed> $args
     * @return array<string, mixed>
     */
    private function reported(?object $on, string $class, string $method, array $args, string $removed = '<script> element'): array
    {
        $out = (new \ReflectionMethod($class, $method))->invokeArgs($on, $args);
        $this->assertIsArray($out);
        $result = isset($out['result']) && is_array($out['result']) ? $out['result'] : $out;
        $this->assertTrue($result['sanitized'] ?? false, "{$class}::{$method}: " . wp_json_encode($out));
        $this->assertContains($removed, $result['removed']);

        return $out;
    }

    private function posts(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
    }

    private function stored_post(string $type): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => 'publish']);
        $this->store_raw($id, Kses_Markup::STORED);

        return $id;
    }

    public function test_lms_lesson_writes_report_what_kses_removed(): void
    {
        $lesson = $this->stored_post('sfwd-lessons');
        $this->log_in_without_unfiltered_html();

        // LearnDash goes through LMS_Adapter, as Tutor and LifterLMS do;
        // LearnPress and MasterStudy are covered in their pack tests.
        $this->reported(new LMS_LearnDash(), LMS_LearnDash::class, 'add', ['lesson', 0, ['title' => 'New', 'content' => Kses_Markup::UNSAFE], self::CONTEXT]);
        $this->reported(new LMS_LearnDash(), LMS_LearnDash::class, 'update_item', [['id' => $lesson, 'content' => Kses_Markup::UNSAFE]]);
        $this->assertStringNotContainsString('<script', get_post($lesson)->post_content);

        $this->reported(new LMS_Thrive_Apprentice(), LMS_Thrive_Apprentice::class, 'update_lesson', [['id' => $lesson, 'content' => Kses_Markup::UNSAFE], self::CONTEXT]);
    }

    public function test_popup_and_testimonial_writes_report_what_kses_removed(): void
    {
        $popup = $this->stored_post('popup');
        $this->log_in_without_unfiltered_html();

        $this->reported(null, Popup_Maker_Pack::class, 'create', [['title' => 'New', 'content' => Kses_Markup::UNSAFE], self::CONTEXT]);
        $this->reported(null, Popup_Maker_Pack::class, 'update', [['id' => $popup, 'content' => Kses_Markup::UNSAFE]]);
        $this->reported(null, Popup_Builder_Pack::class, 'update', [['id' => $popup, 'content' => Kses_Markup::UNSAFE]]);
        // Thrive Ovation drops scripts itself; an iframe still reaches kses.
        $this->reported(null, Thrive_Ovation_Pack::class, 'create', [['title' => 'New', 'content' => '<p>Great</p><iframe src="https://example.org/x"></iframe>'], self::CONTEXT], '<iframe> element');
        $this->reported(null, Thrive_Ovation_Pack::class, 'update', [['id' => $popup, 'content' => '<iframe src="https://example.org/x"></iframe>']], '<iframe> element');
    }

    public function test_a_theme_layout_block_element_reports_what_kses_removed(): void
    {
        $id = $this->stored_post('gp_elements');
        update_post_meta($id, '_generate_element_type', 'block');
        update_post_meta($id, '_generate_block_type', 'site-footer');
        $this->log_in_without_unfiltered_html();

        $this->reported(null, Theme_Layouts_Pack::class, 'update', [new Theme_Layouts_GeneratePress(), ['layout_id' => $id, 'content' => Kses_Markup::UNSAFE]]);
    }

    /**
     * Recorded, not guarded: a memory entry's text has its tags stripped
     * (Memory_Entry::clean_text) before the write, so kses has no markup
     * left to remove.
     */
    public function test_memory_entries_are_plain_text_before_the_save(): void
    {
        $this->log_in_without_unfiltered_html();

        $id = Memory_Store::propose(['text' => 'Use the <b>blue</b> theme<script>x()</script> for landing pages', 'kind' => 'fact']);

        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        $this->assertStringNotContainsString('<', get_post($id)->post_content);
    }
}
