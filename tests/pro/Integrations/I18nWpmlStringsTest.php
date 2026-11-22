<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\I18n_Strings;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WPML_String_Store;
use WPMCP\Integrations\WPML_String_Translation;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\I18n\I18n_Adapter;

require_once __DIR__ . '/../../support/wpml-st-stubs.php';

/**
 * Theme and plugin string translation for WPML String Translation (issue
 * #1053): the same list-string-domains, get-strings and update-strings ops
 * as Polylang, over WPML's own icl_strings and icl_string_translations
 * tables (tests/support/wpml-st-stubs.php). WPML is not loaded; its
 * languages come through its documented wpml_active_languages and
 * wpml_default_language filters.
 */
class I18nWpmlStringsTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_amelia_active', 'wpmcp_action_scheduler_active' ];

    private int $read_more;
    private int $cta;
    private int $tagline;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_wpml_st_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_wpml_st_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_multilingual_active', '__return_true');
        add_filter('wpmcp_wpml_st_active', '__return_true');
        add_filter(I18n_Strings::OPT_IN_FILTER, '__return_true');
        I18n_Adapter::use_plugin_for_tests('wpml');
        add_filter('wpml_active_languages', static fn () => [
            'en' => [ 'code' => 'en', 'native_name' => 'English', 'translated_name' => 'English' ],
            'de' => [ 'code' => 'de', 'native_name' => 'Deutsch', 'translated_name' => 'German' ],
            'fr' => [ 'code' => 'fr', 'native_name' => 'Francais', 'translated_name' => 'French' ],
        ]);
        add_filter('wpml_default_language', static fn () => 'en');

        $this->read_more = wpmcp_test_wpml_string('my-theme', 'read_more', 'Read more');
        $this->cta       = wpmcp_test_wpml_string('my-theme', 'cta', 'Call us today');
        $this->tagline   = wpmcp_test_wpml_string('WP', 'Tagline', 'Just another site');
        wpmcp_test_wpml_translation($this->read_more, 'de', 'Weiterlesen');
        wpmcp_test_wpml_translation($this->cta, 'de', 'Rufen', 0);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    protected function tearDown(): void
    {
        I18n_Adapter::use_plugin_for_tests(null);
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_multilingual_active', 'wpmcp_wpml_st_active', I18n_Strings::OPT_IN_FILTER, 'wpml_active_languages', 'wpml_default_language' ]) as $filter) {
            remove_all_filters($filter);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function got(string $op, array $args = []): array
    {
        return (array) $this->ok($this->read($op, $args))['result'];
    }

    /** language => [value, status] of a string's translation rows. */
    private function translations(int $string_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT language, value, status FROM {$wpdb->prefix}icl_string_translations WHERE string_id = %d ORDER BY language", $string_id), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ $row['language'] ] = [ $row['value'], (int) $row['status'] ];
        }
        return $out;
    }

    private function string_status(int $string_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}icl_strings WHERE id = %d", $string_id));
    }

    public function test_without_string_translation_the_ops_answer_a_named_refusal(): void
    {
        remove_all_filters('wpmcp_wpml_st_active');
        add_filter('wpmcp_wpml_st_active', '__return_false');
        $out = $this->read('list-string-domains');
        $this->assertSame('string_translation_unsupported', $out['error']['code'] ?? null);
        $this->assertStringContainsString('WPML String Translation', (string) ($out['error']['message'] ?? ''));
    }

    public function test_list_string_domains_counts_complete_translations_only(): void
    {
        $out     = $this->got('list-string-domains');
        $domains = array_column($out['domains'], null, 'domain');

        $this->assertSame([ 'my-theme', 'WP' ], array_keys($domains), 'ordered by domain, as the database collates it');
        $this->assertSame(2, $domains['my-theme']['strings']);
        $this->assertSame([ 'de' => 1, 'fr' => 0 ], $domains['my-theme']['translated'], 'a not-translated row (status 0) does not count');
        $this->assertSame([ 'de' => 1, 'fr' => 2 ], $domains['my-theme']['missing']);
        $this->assertSame([ 'de', 'fr' ], $out['languages']);
        $this->assertSame('en', $out['default_language']);
        $this->assertSame('wpml', $out['plugin']);
    }

    public function test_get_strings_lists_translations_missing_languages_search_and_paging(): void
    {
        $out  = $this->got('get-strings', [ 'domain' => 'my-theme' ]);
        $rows = array_column($out['strings'], null, 'string');
        $this->assertSame(2, $out['total']);
        $this->assertSame((string) $this->read_more, $rows['Read more']['key']);
        $this->assertSame('read_more', $rows['Read more']['name']);
        $this->assertSame('Weiterlesen', $rows['Read more']['translations']['de']);
        $this->assertNull($rows['Read more']['translations']['fr']);
        $this->assertSame([ 'fr' ], $rows['Read more']['missing']);
        $this->assertSame([ 'de', 'fr' ], $rows['Call us today']['missing']);

        $out = $this->got('get-strings', [ 'language' => 'de', 'missing_only' => true ]);
        $this->assertSame([ 'Call us today', 'Just another site' ], array_column($out['strings'], 'string'));

        $out = $this->got('get-strings', [ 'search' => 'another' ]);
        $this->assertSame([ 'Just another site' ], array_column($out['strings'], 'string'));

        $out = $this->got('get-strings', [ 'per_page' => 2, 'page' => 2 ]);
        $this->assertSame(3, $out['total']);
        $this->assertSame(2, $out['pages']);
        $this->assertCount(1, $out['strings']);

        $this->assertSame('unknown_language', $this->read('get-strings', [ 'language' => 'xx' ])['error']['code'] ?? null);
    }

    public function test_update_strings_writes_wpml_rows_and_rollback_restores_them_exactly(): void
    {
        $before_rm  = $this->translations($this->read_more);
        $before_cta = $this->translations($this->cta);
        $out        = $this->ok($this->write('update-strings', [
            'language' => 'de',
            'strings'  => [
                [ 'key' => (string) $this->cta, 'translation' => ' Rufen Sie uns an ' ],
                [ 'key' => (string) $this->read_more, 'translation' => 'Mehr lesen' ],
                [ 'key' => (string) $this->tagline, 'translation' => 'Noch eine Seite' ],
            ],
        ]));

        $this->assertSame(3, $out['result']['written']);
        $this->assertSame([ 'de' => [ 'Mehr lesen', 10 ] ], $this->translations($this->read_more));
        $this->assertSame([ 'de' => [ 'Rufen Sie uns an', 10 ] ], $this->translations($this->cta), 'a not-translated row is completed in place');
        $this->assertSame([ 'de' => [ 'Noch eine Seite', 10 ] ], $this->translations($this->tagline), 'a missing row is inserted');
        $this->assertSame(2, $this->string_status($this->tagline), 'partial: translated into de, not fr');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before_rm, $this->translations($this->read_more));
        $this->assertSame($before_cta, $this->translations($this->cta));
        $this->assertSame([], $this->translations($this->tagline), 'the inserted row is gone');
        $this->assertSame(0, $this->string_status($this->tagline));
    }

    public function test_update_strings_empty_translation_clears_it_and_rolls_back(): void
    {
        $out = $this->ok($this->write('update-strings', [
            'language' => 'de',
            'strings'  => [ [ 'key' => (string) $this->read_more, 'translation' => '' ] ],
        ]));
        $this->assertSame(1, $out['result']['cleared']);
        $this->assertSame([], $this->translations($this->read_more));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ 'de' => [ 'Weiterlesen', 10 ] ], $this->translations($this->read_more));
    }

    public function test_update_strings_refuses_unknown_keys_duplicates_and_the_source_language(): void
    {
        $this->assertSame('unknown_string', $this->write('update-strings', [ 'language' => 'de', 'strings' => [ [ 'key' => '999999', 'translation' => 'x' ] ] ])['error']['code'] ?? null);
        $this->assertSame('duplicate_string', $this->write('update-strings', [ 'language' => 'de', 'strings' => [ [ 'key' => (string) $this->cta, 'translation' => 'a' ], [ 'key' => (string) $this->cta, 'translation' => 'b' ] ] ])['error']['code'] ?? null);
        $this->assertSame('source_language', $this->write('update-strings', [ 'language' => 'en', 'strings' => [ [ 'key' => (string) $this->cta, 'translation' => 'x' ] ] ])['error']['code'] ?? null);
        $this->assertSame([ 'de' => [ 'Rufen', 0 ] ], $this->translations($this->cta));
    }

    public function test_update_strings_is_off_until_the_site_opts_in(): void
    {
        remove_all_filters(I18n_Strings::OPT_IN_FILTER);
        $out = $this->write('update-strings', [ 'language' => 'de', 'strings' => [ [ 'key' => (string) $this->cta, 'translation' => 'x' ] ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame([ 'de' => [ 'Rufen', 0 ] ], $this->translations($this->cta));
    }

    public function test_a_translation_is_written_through_wpml_when_its_api_is_loaded(): void
    {
        global $wpdb;
        $calls = [];
        $api   = static function (int $string_id, string $language, string $value, int $status) use (&$calls): int {
            $calls[] = [ $string_id, $language, $value, $status ];
            return 1;
        };
        $handler = new WPML_String_Translation(new WPML_String_Store($wpdb), $api);
        $handler->update_strings([ 'language' => 'fr', 'strings' => [ [ 'key' => (string) $this->cta, 'translation' => 'Appelez-nous' ] ] ]);
        $this->assertSame([ [ $this->cta, 'fr', 'Appelez-nous', 10 ] ], $calls, 'icl_add_string_translation($id, $lang, $value, ICL_TM_COMPLETE)');
    }

    public function test_the_snapshot_covers_the_string_row_and_its_translations_and_needs_manage_options(): void
    {
        $key      = Plugin_Table_Rows_Snapshot::key('wpml_strings', 'strings', [ $this->read_more ]);
        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, $key);
        $this->assertCount(1, $snapshot['data']['rows']);
        $this->assertCount(1, $snapshot['data']['companions']['string_translations']['rows']);
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('wpml_strings', 'translations'), 'only the strings table');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Plugin_Table_Rows_Snapshot::restore($snapshot);
    }
}
