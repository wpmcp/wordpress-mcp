<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Integrations\Yoast_Redirect_Store;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The rest of issue #681 on the theme pair: Yoast SEO Premium redirects
 * (yoast-*-redirect ops over its wpseo-premium-redirects-* options), Rank
 * Math Pro schema templates (rank_math_schema posts) and Yoast's per post
 * type schema defaults (wpseo_titles).
 *
 * Yoast SEO Premium and Rank Math Pro are not loaded; presence is driven
 * through wpmcp_yoast_premium_active and wpmcp_rank_math_pro_active, and the
 * data is seeded in the plugins' own storage format. Yoast SEO (free) is
 * loaded in the shared suite, so the schema defaults read its real options.
 */
class SeoPluginExtrasTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
        add_filter('wpmcp_yoast_premium_active', '__return_true');
        add_filter('wpmcp_rank_math_pro_active', '__return_true');
        add_filter('wpmcp_enable_seo_redirect_write', '__return_true');
        update_option(Yoast_Redirect_Store::BASE, [
            [ 'origin' => 'old-page', 'url' => '/new-page/', 'type' => 301, 'format' => 'plain' ],
            [ 'origin' => '^/blog/(.*)', 'url' => '/news/$1', 'type' => 302, 'format' => 'regex' ],
        ]);
        update_option(Yoast_Redirect_Store::PLAIN, [ 'old-page' => [ 'url' => '/new-page/', 'type' => 301 ] ]);
        update_option(Yoast_Redirect_Store::REGEX, [ '^/blog/(.*)' => [ 'url' => '/news/$1', 'type' => 302 ] ]);
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_yoast_premium_active', 'wpmcp_rank_math_pro_active', 'wpmcp_enable_seo_redirect_write' ] as $filter) {
            remove_all_filters($filter);
        }
        foreach ([ Yoast_Redirect_Store::BASE, Yoast_Redirect_Store::PLAIN, Yoast_Redirect_Store::REGEX, 'wpseo_redirect' ] as $option) {
            delete_option($option);
        }
        if (post_type_exists('rank_math_schema')) {
            unregister_post_type('rank_math_schema');
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function snapshot(): array
    {
        return [ get_option(Yoast_Redirect_Store::BASE), get_option(Yoast_Redirect_Store::PLAIN), get_option(Yoast_Redirect_Store::REGEX) ];
    }

    public function test_without_yoast_premium_the_redirect_ops_answer_plugin_missing(): void
    {
        remove_all_filters('wpmcp_yoast_premium_active');
        add_filter('wpmcp_yoast_premium_active', '__return_false');
        $out = $this->read('yoast-list-redirects');
        $this->assertSame('yoast_premium_inactive', $out['error']['code'] ?? null);
    }

    public function test_list_redirects_reads_the_base_option_with_filters(): void
    {
        $out = $this->ok($this->read('yoast-list-redirects'))['result'];
        $this->assertSame(2, $out['total']);
        $this->assertSame([ 'origin' => 'old-page', 'url' => '/new-page/', 'type' => 301, 'format' => 'plain' ], $out['redirects'][0]);

        $out = $this->ok($this->read('yoast-list-redirects', [ 'format' => 'regex' ]))['result'];
        $this->assertSame([ '^/blog/(.*)' ], array_column($out['redirects'], 'origin'));

        $out = $this->ok($this->read('yoast-list-redirects', [ 'search' => 'new-page' ]))['result'];
        $this->assertSame([ 'old-page' ], array_column($out['redirects'], 'origin'));
    }

    public function test_create_redirect_writes_base_and_export_options_and_rolls_back(): void
    {
        $before = $this->snapshot();
        $out    = $this->ok($this->write('yoast-create-redirect', [ 'origin' => '/summer-sale/', 'url' => '/offers/', 'type' => 307 ]));
        $this->assertSame([ 'origin' => 'summer-sale', 'url' => '/offers/', 'type' => 307, 'format' => 'plain' ], $out['result']['redirect'], 'a plain origin is stored without its slashes, as Yoast does');
        $this->assertSame([ 'url' => '/offers/', 'type' => 307 ], get_option(Yoast_Redirect_Store::PLAIN)['summer-sale']);
        $this->assertCount(3, get_option(Yoast_Redirect_Store::BASE));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_410_needs_no_url_and_other_types_do(): void
    {
        $out = $this->ok($this->write('yoast-create-redirect', [ 'origin' => 'gone', 'type' => 410 ]));
        $this->assertSame('', $out['result']['redirect']['url']);
        $this->assertSame('invalid_argument', $this->write('yoast-create-redirect', [ 'origin' => 'nowhere', 'type' => 301 ])['error']['code'] ?? null);
    }

    public function test_create_refuses_a_duplicate_origin_a_loop_and_a_bad_regex(): void
    {
        $this->assertSame('redirect_exists', $this->write('yoast-create-redirect', [ 'origin' => 'old-page', 'url' => '/x/' ])['error']['code'] ?? null);
        $this->assertSame('redirect_loop', $this->write('yoast-create-redirect', [ 'origin' => 'loop', 'url' => '/loop/' ])['error']['code'] ?? null);
        $this->assertSame('invalid_regex', $this->write('yoast-create-redirect', [ 'origin' => '([a-z', 'url' => '/x/', 'format' => 'regex' ])['error']['code'] ?? null);
    }

    public function test_update_redirect_changes_only_passed_fields_and_rolls_back(): void
    {
        $before = $this->snapshot();
        $out    = $this->ok($this->write('yoast-update-redirect', [ 'origin' => 'old-page', 'url' => '/newer-page/' ]));
        $this->assertSame([ 'origin' => 'old-page', 'url' => '/newer-page/', 'type' => 301, 'format' => 'plain' ], $out['result']['redirect']);
        $this->assertSame([ 'url' => '/newer-page/', 'type' => 301 ], get_option(Yoast_Redirect_Store::PLAIN)['old-page']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->snapshot());

        $this->assertSame('redirect_not_found', $this->write('yoast-update-redirect', [ 'origin' => 'missing', 'url' => '/x/' ])['error']['code'] ?? null);
    }

    public function test_delete_redirect_removes_it_from_every_option_and_rolls_back(): void
    {
        $before = $this->snapshot();
        $out = $this->ok($this->write('yoast-delete-redirect', [ 'origin' => '^/blog/(.*)', 'format' => 'regex' ], true));
        $this->assertSame([], get_option(Yoast_Redirect_Store::REGEX));
        $this->assertCount(1, get_option(Yoast_Redirect_Store::BASE));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_writes_are_refused_while_yoast_serves_redirects_from_a_server_file(): void
    {
        update_option('wpseo_redirect', [ 'disable_php_redirect' => 'on', 'separate_file' => 'off' ]);
        $out = $this->write('yoast-create-redirect', [ 'origin' => 'file-mode', 'url' => '/x/' ]);
        $this->assertSame('redirect_file_mode', $out['error']['code'] ?? null);
    }

    public function test_redirect_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_seo_redirect_write');
        $out = $this->write('yoast-create-redirect', [ 'origin' => 'opt-in', 'url' => '/x/' ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertCount(2, get_option(Yoast_Redirect_Store::BASE));
    }

    public function test_rank_math_schema_templates_list_type_and_display_conditions(): void
    {
        register_post_type('rank_math_schema', [ 'public' => false ]);
        $id = self::factory()->post->create([ 'post_type' => 'rank_math_schema', 'post_title' => 'Product template', 'post_status' => 'publish' ]);
        update_post_meta($id, 'rank_math_schema_Product', [
            '@type'    => 'Product',
            'name'     => '%seo_title%',
            'metadata' => [ 'title' => 'Product', 'type' => 'template', 'displayConditions' => [ [ 'condition' => 'include', 'type' => 'singular', 'value' => 'product' ] ] ],
        ]);

        $out = $this->ok($this->read('rankmath-list-schema-templates'))['result'];
        $this->assertSame(1, $out['total']);
        $this->assertSame($id, $out['templates'][0]['id']);
        $this->assertSame('Product template', $out['templates'][0]['title']);
        $this->assertSame([ 'Product' ], $out['templates'][0]['schema_types']);
        $this->assertSame([ [ 'condition' => 'include', 'type' => 'singular', 'value' => 'product' ] ], $out['templates'][0]['display_conditions']);

        remove_all_filters('wpmcp_rank_math_pro_active');
        add_filter('wpmcp_rank_math_pro_active', '__return_false');
        $this->assertSame('rank_math_pro_inactive', $this->read('rankmath-list-schema-templates')['error']['code'] ?? null);
    }

    public function test_yoast_schema_defaults_read_the_per_post_type_settings(): void
    {
        if (! class_exists('WPSEO_Options')) {
            $this->markTestSkipped('Yoast SEO is not loaded in this leg.');
        }
        $titles                             = (array) get_option('wpseo_titles', []);
        $titles['schema-page-type-page']    = 'AboutPage';
        $titles['schema-article-type-post'] = 'NewsArticle';
        update_option('wpseo_titles', $titles);
        \WPSEO_Options::clear_cache();

        $out   = $this->ok($this->read('yoast-get-schema-defaults'))['result'];
        $types = array_column($out['post_types'], null, 'post_type');
        $this->assertSame('AboutPage', $types['page']['page_type']);
        $this->assertSame('NewsArticle', $types['post']['article_type']);
    }
}
