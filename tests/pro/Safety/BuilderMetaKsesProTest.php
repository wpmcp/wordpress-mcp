<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Kses\Kses_Markup;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;
use WPMCP\Tests\Pro\Builders\BeaverBuilderNodesTest;
use WPMCP\Tests\Pro\Builders\BreakdanceTreeTest;
use WPMCP\Tools\Builders\Beaver_Builder_Content;
use WPMCP\Tools\Builders\Bricks_Content;
use WPMCP\Tools\Builders\Update_Builder_Content;

/**
 * update-builder-content on the builders that keep their layout in post
 * meta (Bricks, Breakdance, Beaver Builder; classic Oxygen is covered in
 * OxygenClassicAdapterTest), for a user without unfiltered_html (a site
 * administrator on a network): a new value kses would change is refused
 * with unfiltered_html_required (403) before the snapshot, so nothing is
 * stored and no undo point is left; a plain value is written.
 */
class BuilderMetaKsesProTest extends \WP_UnitTestCase
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
        remove_all_filters('wpmcp_breakdance_active');
        remove_all_filters('wpmcp_beaver_builder_active');
        parent::tearDown();
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function assert_refused_and_unchanged(mixed $out, int $post_id, array $before): void
    {
        $this->assert_kses_refused($out);
        $this->assertSame(403, $out->get_error_data()['status']);
        wp_cache_delete($post_id, 'post_meta');
        $this->assertSame($before, get_post_meta($post_id));
        $this->assertSame(0, Snapshot_Store::row_count());
    }

    public function test_a_bricks_value_kses_would_change_is_refused_before_the_snapshot(): void
    {
        $tree = [['id' => 'abc123', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => ['text' => '<p>Old</p>']]];
        $page = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, Bricks_Content::META_KEY, wp_slash($tree));
        $before = get_post_meta($page);
        $this->log_in_without_unfiltered_html();

        $bad                         = $tree;
        $bad[0]['settings']['text'] = '<a href="javascript:alert(1)">Go</a>';
        $out                         = (new Update_Builder_Content())->handle(['post_id' => $page, 'builder' => 'bricks', 'content' => wp_json_encode($bad)]);
        $this->assert_refused_and_unchanged($out, $page, $before);

        $ok                         = $tree;
        $ok[0]['settings']['text'] = '<p>New</p>';
        $this->assertIsArray((new Update_Builder_Content())->handle(['post_id' => $page, 'builder' => 'bricks', 'content' => wp_json_encode($ok)]));
        $this->assertSame($ok, Bricks_Content::get($page));
    }

    public function test_a_breakdance_value_kses_would_change_is_refused_before_the_snapshot(): void
    {
        add_filter('wpmcp_breakdance_active', '__return_true');
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->raw_meta($page, '_breakdance_data', BreakdanceTreeTest::fixture_meta());
        $before = get_post_meta($page);
        $this->log_in_without_unfiltered_html();

        $update = fn (string $text) => (new Update_Builder_Content())->handle(['post_id' => $page, 'builder' => 'breakdance', 'operation' => 'update', 'path' => '102', 'attrs' => ['content' => ['content' => ['text' => $text]]]]);

        $this->assert_refused_and_unchanged($update('<img src="x.png" onerror="alert(1)">'), $page, $before);
        $this->assertIsArray($update('<p>New</p>'));
    }

    public function test_a_beaver_builder_value_kses_would_change_is_refused_before_the_snapshot(): void
    {
        add_filter('wpmcp_beaver_builder_active', '__return_true');
        $page = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<div class="fl-builder-content"></div>']);
        $this->raw_meta($page, '_fl_builder_enabled', '1');
        $this->raw_meta($page, '_fl_builder_data', BeaverBuilderNodesTest::fixture_serialized());
        $before = get_post_meta($page);
        $this->log_in_without_unfiltered_html();

        $update = fn (string $text) => (new Update_Builder_Content())->handle(['post_id' => $page, 'builder' => 'beaver-builder', 'operation' => 'update', 'path' => 'm8y3n5c1p4ve', 'attrs' => ['text' => $text]]);

        $this->assert_refused_and_unchanged($update(Kses_Markup::UNSAFE), $page, $before);
        $this->assertIsArray($update('<p>New</p>'));
        $this->assertSame('<p>New</p>', Beaver_Builder_Content::get_nodes($page)['m8y3n5c1p4ve']->settings->text);
    }
}
