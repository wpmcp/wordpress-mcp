<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Kses\Kses_Markup;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Migration\Find_Replace_Content;

/**
 * The generic meta writers (set-post-meta, and the meta argument of
 * create-post and update-post) and the rows page builders render a page
 * from.
 *
 * - A key is judged as WordPress stores it: update_post_meta() unslashes the
 *   key and create-post and update-post store sanitize_key() of it, so a
 *   protected key spelled with a backslash or a character sanitize_key()
 *   drops is refused like the key itself.
 * - A user without unfiltered_html (on a network, a site administrator who
 *   is not a super admin) is refused a builder's layout or code row that
 *   WordPress does not protect (BeTheme, Brizy, SiteOrigin, Kirki, Thrive,
 *   Breakdance 1.x, classic Oxygen): update-builder-content writes those
 *   with the values checked. A user with unfiltered_html writes them as
 *   before, and other keys are written as before.
 */
final class BuilderMetaKeysTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
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

    /** @return array<string, array{0: string}> */
    public static function disguised_protected_keys(): array
    {
        return [
            'leading backslash'      => ['\\_elementor_data'],
            'escaped underscore'     => ['\\_bricks_page_content_2'],
            'leading space'          => [' _oxygen_data'],
            'dropped punctuation'    => ['!_fl_builder_data'],
            'upper case'             => ['_ELEMENTOR_DATA'],
            'fullwidth underscore'   => ["\u{FF3F}elementor_data"],
            'zero width space'       => ["\u{200B}_elementor_data"],
            'trailing space'         => ['_elementor_data '],
        ];
    }

    /** @dataProvider disguised_protected_keys */
    public function test_set_post_meta_refuses_a_protected_key_in_any_spelling(string $key): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, '_elementor_data', '[]');
        $this->log_in_with_unfiltered_html();

        try {
            (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => $key, 'value' => '[{"widgetType":"html"}]']);
            $this->fail("The key '{$key}' reached a protected row.");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('meta key', $e->getMessage());
        }
        wp_cache_delete($post_id, 'post_meta');
        $this->assertSame('[]', get_post_meta($post_id, '_elementor_data', true), 'A key that matches the stored row by collation must not overwrite it.');
    }

    /** @dataProvider disguised_protected_keys */
    public function test_create_and_update_post_refuse_a_protected_key_in_any_spelling(string $key): void
    {
        $this->log_in_with_unfiltered_html();
        $post_id = self::factory()->post->create();

        foreach ([
            fn () => (new Create_Post())->handle(['title' => 'X', 'meta' => [$key => [['id' => 'a', 'name' => 'code']]]]),
            fn () => (new Update_Post())->handle(['post_id' => $post_id, 'meta' => [$key => [['id' => 'a', 'name' => 'code']]]]),
        ] as $write) {
            try {
                $write();
                $this->fail("The key '{$key}' reached a protected row.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('meta key', $e->getMessage());
            }
        }
        $this->assertFalse(metadata_exists('post', $post_id, sanitize_key($key)));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function builder_rows(): array
    {
        return [
            'Thrive layout'          => ['tve_updated_post', '<div class="thrv_wrapper">' . Kses_Markup::UNSAFE . '</div>'],
            'Thrive revision layout' => ['tve_revision_tve_updated_post', Kses_Markup::UNSAFE],
            'Thrive page scripts'    => ['tve_global_scripts', 'alert(1)'],
            'BeTheme layout'         => ['mfn-page-items', base64_encode(serialize([['attr' => ['title' => Kses_Markup::UNSAFE]]]))],
            'Brizy data'             => ['brizy', ['brizy-post' => ['editor_data' => base64_encode('{"items":[]}')]]],
            'SiteOrigin layout'      => ['panels_data', ['widgets' => [['text' => Kses_Markup::UNSAFE]]]],
            'Kirki layout'           => ['kirki', ['blocks' => []]],
            'Breakdance 1.x layout'  => ['breakdance_data', '{"tree_json_string":"{}"}'],
            'classic Oxygen tree'    => ['ct_builder_json', '{"id":0,"name":"root","children":[]}'],
            'Visual Composer page'   => ['vcv-pageContent', '%7B%7D'],
        ];
    }

    /** @dataProvider builder_rows */
    public function test_a_user_without_unfiltered_html_is_refused_a_builder_row(string $key, mixed $value): void
    {
        $post_id = self::factory()->post->create();
        $this->log_in_without_unfiltered_html();

        $writes = [fn () => (new Update_Post())->handle(['post_id' => $post_id, 'meta' => [$key => $value]])];
        if (is_string($value)) {
            $writes[] = fn () => (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => $key, 'value' => $value]);
        }
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail("A user without unfiltered_html wrote {$key}.");
            } catch (Post_Write_Refused $e) {
                $this->assert_kses_refused($e->error());
                $this->assertSame(403, $e->error()->get_error_data()['status']);
            }
        }
        $this->assertFalse(metadata_exists('post', $post_id, $key));
        $this->assertSame(0, Snapshot_Store::row_count());
    }

    public function test_find_replace_refuses_a_builder_row(): void
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'tve_updated_post', '<p>old.example</p>');
        $this->log_in_without_unfiltered_html();

        try {
            (new Find_Replace_Content())->handle(['search' => 'old.example', 'replace' => 'new.example', 'fields' => ['meta'], 'meta_keys' => ['tve_updated_post'], 'dry_run' => false]);
            $this->fail('A user without unfiltered_html replaced text in a Thrive layout.');
        } catch (Post_Write_Refused $e) {
            $this->assert_kses_refused($e->error());
        }
        $this->assertSame('<p>old.example</p>', get_post_meta($post_id, 'tve_updated_post', true));
    }

    public function test_create_post_refuses_a_builder_row_before_creating_the_post(): void
    {
        $this->log_in_without_unfiltered_html();
        $before = (int) wp_count_posts('post')->draft;

        try {
            (new Create_Post())->handle(['title' => 'Landing', 'meta' => ['tve_updated_post' => Kses_Markup::UNSAFE]]);
            $this->fail('A user without unfiltered_html wrote a Thrive layout.');
        } catch (Post_Write_Refused $e) {
            $this->assert_kses_refused($e->error());
        }
        wp_cache_flush();
        $this->assertSame($before, (int) wp_count_posts('post')->draft);
    }

    /** @dataProvider builder_rows */
    public function test_a_user_with_unfiltered_html_writes_a_builder_row(string $key, mixed $value): void
    {
        $post_id = self::factory()->post->create();
        $this->log_in_with_unfiltered_html();

        (new Update_Post())->handle(['post_id' => $post_id, 'meta' => [$key => $value]]);

        // update-post stores the key through sanitize_key(), as before.
        $this->assertSame($value, get_post_meta($post_id, sanitize_key($key), true));
    }

    public function test_other_keys_are_written_as_before(): void
    {
        $post_id = self::factory()->post->create();
        $this->log_in_without_unfiltered_html();

        (new Set_Post_Meta())->handle(['post_id' => $post_id, 'key' => 'subtitle', 'value' => 'Spring sale']);
        (new Update_Post())->handle(['post_id' => $post_id, 'meta' => ['hero_color' => '#ff0000']]);

        $this->assertSame('Spring sale', get_post_meta($post_id, 'subtitle', true));
        $this->assertSame('#ff0000', get_post_meta($post_id, 'hero_color', true));
    }
}
