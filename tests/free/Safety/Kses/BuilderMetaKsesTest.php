<?php

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Tools\Builders\Beaver_Builder_Content;
use WPMCP\Tools\Builders\Breakdance_Content;
use WPMCP\Tools\Builders\Breakdance_Tree;
use WPMCP\Tools\Builders\Bricks_Content;
use WPMCP\Tools\Builders\Oxygen_Classic_Content;

/**
 * The builders that keep their layout in post meta (Bricks, Breakdance and
 * Oxygen 6 on the same engine, Beaver Builder, classic Oxygen), for a user
 * without unfiltered_html (a site administrator on a network). Meta is not
 * run through kses on save, so the storage layer runs the check Elementor's
 * does: a new string wp_kses_post would change (a script, an event handler,
 * a javascript: URL) is refused with unfiltered_html_required and nothing
 * is written; strings the page already held, plain markup, and every write
 * by a user with unfiltered_html go through as before.
 */
class BuilderMetaKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    /** @return array<string, array{0: string}> */
    public static function unsafe_values(): array
    {
        return [
            'script'        => [Kses_Markup::UNSAFE],
            'event handler' => ['<img src="x.png" onerror="alert(1)">'],
            'javascript url' => ['<a href="javascript:alert(1)">Go</a>'],
        ];
    }

    private function refusal(callable $write): \WP_Error
    {
        try {
            $write();
        } catch (Post_Write_Refused $e) {
            return $e->error();
        }
        $this->fail('The builder write kses would change was not refused.');
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

    /** @return list<array<string, mixed>> */
    private static function bricks(string $text): array
    {
        return [['id' => 'abc123', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => ['text' => $text]]];
    }

    /** @dataProvider unsafe_values */
    public function test_a_bricks_layout_with_a_new_value_kses_would_change_is_refused(string $unsafe): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash(self::bricks('<p>Old</p>')));
        $this->log_in_without_unfiltered_html();

        $error = $this->refusal(fn () => Bricks_Content::save($page, self::bricks($unsafe)));

        $this->assert_kses_refused($error);
        $this->assertSame(403, $error->get_error_data()['status']);
        $this->assertSame(self::bricks('<p>Old</p>'), Bricks_Content::get($page));
    }

    public function test_a_bricks_layout_kses_keeps_or_the_page_already_held_is_written(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash(self::bricks(Kses_Markup::STORED)));
        $this->log_in_without_unfiltered_html();

        $kept   = self::bricks(Kses_Markup::STORED);
        $kept[] = ['id' => 'def456', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => ['text' => '<p>Plain & simple</p>']];
        Bricks_Content::save($page, $kept);

        $this->assertSame($kept, Bricks_Content::get($page));
    }

    public function test_a_user_with_unfiltered_html_writes_any_bricks_value(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Bricks_Content::save($page, self::bricks(Kses_Markup::UNSAFE));

        $this->assertSame(self::bricks(Kses_Markup::UNSAFE), Bricks_Content::get($page));
    }

    public function test_a_bricks_copy_of_values_another_page_holds_is_written(): void
    {
        $source = self::bricks(Kses_Markup::STORED);
        $copy   = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_without_unfiltered_html();

        Bricks_Content::save($copy, $source, $source);

        $this->assertSame($source, Bricks_Content::get($copy));
    }

    // ------------------------------------------- Breakdance and Oxygen 6

    private static function breakdance(string $text): object
    {
        [$doc] = Breakdance_Tree::add(Breakdance_Tree::blank(), '', null, ['type' => 'EssentialElements\\Text', 'properties' => ['content' => ['content' => ['text' => $text]]]]);

        return $doc;
    }

    /** @return array<string, array{0: string}> */
    public static function engines(): array
    {
        return ['breakdance' => ['breakdance'], 'oxygen 6' => ['oxygen']];
    }

    /** @dataProvider engines */
    public function test_a_breakdance_engine_document_with_a_new_value_kses_would_change_is_refused(string $builder): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        Breakdance_Content::save($page, self::breakdance('<p>Old</p>'), $builder);
        $before = get_post_meta($page, Breakdance_Content::data_key($builder, $page), true);
        $this->log_in_without_unfiltered_html();

        foreach (self::unsafe_values() as [$unsafe]) {
            $this->assert_kses_refused($this->refusal(fn () => Breakdance_Content::save($page, self::breakdance($unsafe), $builder)));
        }

        $this->assertSame($before, get_post_meta($page, Breakdance_Content::data_key($builder, $page), true));
    }

    /** @dataProvider engines */
    public function test_a_breakdance_engine_document_kses_keeps_is_written(string $builder): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_without_unfiltered_html();

        Breakdance_Content::save($page, self::breakdance('<p>Plain</p>'), $builder);

        $this->assertEquals(self::breakdance('<p>Plain</p>'), Breakdance_Content::get_document($page, $builder));
    }

    /** @dataProvider engines */
    public function test_a_user_with_unfiltered_html_writes_any_breakdance_engine_value(string $builder): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Breakdance_Content::save($page, self::breakdance(Kses_Markup::UNSAFE), $builder);

        $this->assertEquals(self::breakdance(Kses_Markup::UNSAFE), Breakdance_Content::get_document($page, $builder));
    }

    // ------------------------------------------------------ Beaver Builder

    /** @return array<string, object> */
    private static function beaver(string $text): array
    {
        return [
            'r1' => (object) ['node' => 'r1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => (object) []],
            'm1' => (object) ['node' => 'm1', 'type' => 'module', 'parent' => 'r1', 'position' => 0, 'settings' => (object) ['type' => 'rich-text', 'text' => $text]],
        ];
    }

    /** @dataProvider unsafe_values */
    public function test_a_beaver_builder_layout_with_a_new_value_kses_would_change_is_refused(string $unsafe): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        Beaver_Builder_Content::save($page, self::beaver('<p>Old</p>'));
        $this->log_in_without_unfiltered_html();

        $this->assert_kses_refused($this->refusal(fn () => Beaver_Builder_Content::save($page, self::beaver($unsafe))));
        $this->assert_kses_refused($this->refusal(fn () => Beaver_Builder_Content::save_layout($page, self::beaver('<p>Old</p>'), (object) ['css' => '', 'js' => '', 'html' => $unsafe])));
        $this->assert_kses_refused($this->refusal(fn () => Beaver_Builder_Content::merge_settings($page, (object) ['css' => $unsafe, 'js' => ''])));

        $this->assertEquals(self::beaver('<p>Old</p>'), Beaver_Builder_Content::get_nodes($page));
        $this->assertNull(Beaver_Builder_Content::get_settings($page));
    }

    public function test_a_beaver_builder_layout_kses_keeps_is_written(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_without_unfiltered_html();

        Beaver_Builder_Content::save($page, self::beaver('<p>Plain</p>'));

        $this->assertEquals(self::beaver('<p>Plain</p>'), Beaver_Builder_Content::get_nodes($page));
    }

    public function test_a_user_with_unfiltered_html_writes_any_beaver_builder_value(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->log_in_with_unfiltered_html();

        Beaver_Builder_Content::save($page, self::beaver(Kses_Markup::UNSAFE));

        $this->assertEquals(self::beaver(Kses_Markup::UNSAFE), Beaver_Builder_Content::get_nodes($page));
    }

    // ------------------------------------------------------ classic Oxygen

    private static function oxygen(string $text): string
    {
        return (string) wp_json_encode(['id' => 0, 'name' => 'root', 'depth' => 0, 'children' => [
            ['id' => 1, 'name' => 'ct_text_block', 'options' => ['ct_id' => 1, 'ct_parent' => 0, 'selector' => 'text_block-1-1', 'ct_content' => $text], 'depth' => 1],
        ]]);
    }

    /** @dataProvider unsafe_values */
    public function test_a_classic_oxygen_tree_with_a_new_value_kses_would_change_is_refused(string $unsafe): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, '_' . Oxygen_Classic_Content::JSON_KEY, wp_slash(self::oxygen('<p>Old</p>')));
        $this->log_in_without_unfiltered_html();

        $this->assert_kses_refused($this->refusal(fn () => Oxygen_Classic_Content::save($page, self::oxygen($unsafe), null)));

        $this->assertSame(self::oxygen('<p>Old</p>'), Oxygen_Classic_Content::get_json($page));
    }

    public function test_a_classic_oxygen_tree_kses_keeps_is_written_and_any_tree_by_a_user_with_unfiltered_html(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, '_' . Oxygen_Classic_Content::JSON_KEY, wp_slash(self::oxygen('<p>Old</p>')));
        $this->log_in_without_unfiltered_html();
        Oxygen_Classic_Content::save($page, self::oxygen('<p>Plain</p>'), null);
        $this->assertSame(self::oxygen('<p>Plain</p>'), Oxygen_Classic_Content::get_json($page));

        $this->log_in_with_unfiltered_html();
        Oxygen_Classic_Content::save($page, self::oxygen(Kses_Markup::UNSAFE), null);
        $this->assertSame(self::oxygen(Kses_Markup::UNSAFE), Oxygen_Classic_Content::get_json($page));
    }
}
