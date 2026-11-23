<?php

namespace WPMCP\Tests\Free\Safety\Kses;

use WPMCP\Integrations\Advanced_Ads_Pack;
use WPMCP\Integrations\BetterDocs_Pack;
use WPMCP\Integrations\CartFlows_Pack;
use WPMCP\Integrations\Core_Fonts_Pack;
use WPMCP\Integrations\Cpt_Builder_Pack;
use WPMCP\Integrations\EDD_Pack;
use WPMCP\Integrations\Gamipress_Pack;
use WPMCP\Integrations\I18n_Page_Text;
use WPMCP\Integrations\Kses_Check;
use WPMCP\Integrations\Listings_Pack;
use WPMCP\Integrations\Operation_Refused;

/**
 * Issue #1243, the plugin-data and theme packs that store post_content, for
 * a user without unfiltered_html (a site administrator on a network).
 * Their content is plain post content: WordPress sanitizes it as its editor
 * does (#440), the op goes ahead, and its result carries sanitized:true and
 * what kses removed. Data kept in post_content as JSON (font settings,
 * custom post type definitions) is refused with unfiltered_html_required
 * before anything is created, since sanitizing it corrupts the data. Each
 * op handler is called directly.
 */
class PacksKsesTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private const CONTEXT = ['tool_name' => 'plugin-data-write', 'operation' => 'x', 'session_id' => 'kses-1243'];

    /**
     * @param array<int, mixed> $args
     * @return array<string, mixed>
     */
    private function call(string $class, string $method, array $args): array
    {
        $out = (new \ReflectionMethod($class, $method))->invokeArgs(null, $args);
        $this->assertIsArray($out);

        return $out;
    }

    /** @param array<string, mixed> $out A handler's result, or its ['result' => ...] envelope. */
    private function assert_reported(array $out): void
    {
        $result = isset($out['result']) && is_array($out['result']) ? $out['result'] : $out;
        $this->assertTrue($result['sanitized'] ?? false, (string) wp_json_encode($out));
        $this->assertContains('<script> element', $result['removed']);
    }

    /** @param array<int, mixed> $args */
    private function refused(string $class, string $method, array $args): void
    {
        try {
            (new \ReflectionMethod($class, $method))->invokeArgs(null, $args);
        } catch (Operation_Refused $e) {
            $this->assertSame('unfiltered_html_required', $e->error_code(), $e->getMessage());
            $this->assertSame(403, $e->error_data()['status'] ?? null);
            return;
        }
        $this->fail("{$class}::{$method} wrote data kses would change.");
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

    /** @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}> class, create, update, id arg, post type */
    public static function content_packs(): array
    {
        return [
            'advanced ads' => [Advanced_Ads_Pack::class, 'create', 'update', 'id', 'advanced_ads'],
            'betterdocs'   => [BetterDocs_Pack::class, 'create_doc', 'update_doc', 'id', 'docs'],
            'edd'          => [EDD_Pack::class, 'create_download', 'update_download', 'download_id', 'download'],
            'gamipress'    => [Gamipress_Pack::class, 'create', 'update', 'id', 'badge'],
        ];
    }

    /** @dataProvider content_packs */
    public function test_a_pack_create_and_update_report_what_kses_removed(string $class, string $create, string $update, string $id_arg, string $type): void
    {
        $id = $this->stored_post($type);
        $this->log_in_without_unfiltered_html();

        $this->assert_reported($this->call($class, $create, [['title' => 'New', 'type' => $type, 'content' => Kses_Markup::UNSAFE], self::CONTEXT]));
        $this->assert_reported($this->call($class, $update, [[$id_arg => $id, 'content' => str_replace('Stored', 'Edited', Kses_Markup::STORED)]]));
        $this->assertStringNotContainsString('<script', get_post($id)->post_content);

        $out = $this->call($class, $update, [[$id_arg => $id, 'content' => '<p>Plain</p>']]);
        $this->assertArrayNotHasKey('sanitized', $out, 'Content kses keeps is not reported.');
    }

    public function test_a_listing_create_and_update_report_what_kses_removed(): void
    {
        $id = $this->stored_post('job_listing');
        $this->log_in_without_unfiltered_html();

        $this->assert_reported($this->call(Listings_Pack::class, 'create_listing', ['jobmanager', ['title' => 'New', 'post_type' => 'job_listing', 'content' => Kses_Markup::UNSAFE], self::CONTEXT]));
        $this->assert_reported($this->call(Listings_Pack::class, 'update_listing', ['jobmanager', ['id' => $id, 'content' => Kses_Markup::UNSAFE]]));
    }

    /** The translation op reports from the content apply() will write: the runs, and the markup around them. */
    public function test_translating_text_on_a_page_holding_stored_markup_reports_what_kses_removed(): void
    {
        $content = '<p>Hello world</p>' . Kses_Markup::STORED;
        $id      = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->store_raw($id, $content);
        $this->log_in_without_unfiltered_html();
        $key = null;
        foreach (I18n_Page_Text::segments(get_post($id)) as $segment) {
            if ('Hello world' === $segment['text']) {
                $key = $segment['key'];
            }
        }
        $this->assertNotNull($key, 'The fixture has a translatable run.');

        $planned = I18n_Page_Text::planned_content(get_post($id), [$key => 'Hola mundo']);

        $this->assertSame('<p>Hola mundo</p>' . Kses_Markup::STORED, $planned);
        $this->assertSame(['sanitized' => true, 'removed' => ['<script> element']], Kses_Check::report($id, $planned));
    }

    /**
     * Font settings and custom post type definitions are JSON in
     * post_content: any change kses would make to them is refused, an
     * entity it adds included ("Books & Films" would be stored as
     * "Books &amp; Films").
     */
    public function test_json_settings_kses_would_change_are_refused(): void
    {
        $this->log_in_without_unfiltered_html();
        $before = $this->posts();

        $this->refused(Core_Fonts_Pack::class, 'insert', [['post_type' => 'wp_font_family', 'post_title' => 'Serif', 'post_content' => (string) wp_json_encode(['fontFamily' => 'A & B'])]]);
        $this->refused(Cpt_Builder_Pack::class, 'create', ['post_type', ['slug' => 'film', 'label' => 'Books & Films'], self::CONTEXT]);
        $this->assertSame($before, $this->posts(), 'Nothing was created.');

        Kses_Check::assert_exact(0, (string) wp_json_encode(['labels' => ['name' => 'Books']]), 'definition');
    }

    /** Recorded, not guarded: CartFlows flow and step posts carry no content. */
    public function test_cartflows_posts_are_created_with_empty_content(): void
    {
        $this->log_in_without_unfiltered_html();

        $id = (int) (new \ReflectionMethod(CartFlows_Pack::class, 'insert'))->invoke(null, 'cartflows_flow', 'Flow <b>one</b>', 'draft');

        $this->assertSame('', get_post($id)->post_content);
    }
}
