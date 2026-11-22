<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Search_Plugin_Support;
use WPMCP\Integrations\SearchWP_Status;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * SearchWP (issue #993) on the theme pair, next to Relevanssi and
 * FiboSearch (#700). SearchWP is not loaded here: presence is driven through
 * wpmcp_searchwp_active, and its data is kept the way SearchWP 4 keeps it:
 * the engines config in the searchwp_engines option (engine name => label,
 * settings and sources, each source with its attribute weights), the index
 * queue in {prefix}searchwp_status (one row per entry: source, queued,
 * indexed, omitted), the index in {prefix}searchwp_index and the search log
 * in {prefix}searchwp_log (query, engine, tstamp, hits).
 *
 * @group search-plugins
 */
class SearchWPStatusTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-searchwp-settings'        => 'read',
        'get-searchwp-index-status'    => 'read',
        'list-searchwp-top-searches'   => 'read',
        'rebuild-searchwp-index'       => 'destructive',
        'update-searchwp-engine'       => 'write',
    ];

    private const TABLES = [ 'searchwp_status', 'searchwp_index', 'searchwp_log' ];

    /** @var list<int> */
    private array $rebuilds = [];

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        self::drop();
        $wpdb->query("CREATE TABLE {$p}searchwp_status (id varchar(80) NOT NULL, source varchar(80) NOT NULL, queued datetime NULL, indexed datetime NULL, omitted datetime NULL, site bigint(20) NOT NULL DEFAULT 1, PRIMARY KEY (id, source, site))");
        $wpdb->query("CREATE TABLE {$p}searchwp_index (token bigint(20) unsigned NOT NULL, occurrences bigint(20) unsigned NOT NULL, id varchar(80) NOT NULL, attribute varchar(80) NOT NULL, source varchar(80) NOT NULL, site bigint(20) unsigned NOT NULL DEFAULT 1)");
        $wpdb->query("CREATE TABLE {$p}searchwp_log (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, query varchar(80) NOT NULL, engine varchar(191) NOT NULL DEFAULT 'default', tstamp timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, hits mediumint(9) unsigned NOT NULL, site mediumint(9) unsigned NOT NULL DEFAULT 1, PRIMARY KEY (id))");
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop();
    }

    private static function drop(): void
    {
        global $wpdb;
        foreach (self::TABLES as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Snapshot_Store::install();
        foreach (self::TABLES as $table) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . $table));
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_searchwp_active', '__return_true');
        add_filter('wpmcp_searchwp_rebuild_callback', fn () => function (): void {
            $this->rebuilds[] = 1;
        });
        update_option('searchwp_engines', [
            'default' => [
                'label'    => 'Default',
                'settings' => [ 'stemming' => true, 'adminengine' => false ],
                'sources'  => [
                    'post.post' => [ 'attributes' => [ 'title' => 80, 'content' => 5, 'slug' => 60 ], 'rules' => [], 'options' => [] ],
                    'post.page' => [ 'attributes' => [ 'title' => 80, 'content' => 5 ], 'rules' => [], 'options' => [] ],
                ],
            ],
        ]);
        foreach ([ [ '1', 'indexed' ], [ '2', 'indexed' ], [ '3', 'queued' ], [ '4', 'omitted' ] ] as [ $id, $state ]) {
            $wpdb->insert($wpdb->prefix . 'searchwp_status', [ 'id' => $id, 'source' => 'post.post', $state => '2026-11-01 10:00:00' ]);
        }
        $wpdb->insert($wpdb->prefix . 'searchwp_index', [ 'token' => 1, 'occurrences' => 2, 'id' => '1', 'attribute' => 'title', 'source' => 'post.post' ]);
        $now = gmdate('Y-m-d H:i:s');
        foreach ([ [ 'shoes', 4 ], [ 'shoes', 4 ], [ 'ada@example.test', 0 ], [ 'boots', 0 ] ] as [ $query, $hits ]) {
            $wpdb->insert($wpdb->prefix . 'searchwp_log', [ 'query' => $query, 'engine' => 'default', 'tstamp' => $now, 'hits' => $hits ]);
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_searchwp_active');
        remove_all_filters('wpmcp_searchwp_rebuild_callback');
        remove_all_filters(Search_Plugin_Support::ENABLE_FILTER);
        delete_option('searchwp_engines');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args = [], bool $confirm = false): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
    }

    public function test_every_op_is_a_manage_options_op_on_the_theme_pair(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_searchwp_the_ops_answer_searchwp_inactive(): void
    {
        remove_all_filters('wpmcp_searchwp_active');
        add_filter('wpmcp_searchwp_active', '__return_false');
        $out = (new Theme_Integration())->handle_read([ 'operation' => 'get-searchwp-settings', 'args' => [] ]);
        $this->assertSame('searchwp_inactive', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_presence_defaults_to_the_searchwp_constant(): void
    {
        remove_all_filters('wpmcp_searchwp_active');
        $this->assertSame(defined('SEARCHWP_VERSION'), SearchWP_Status::is_active());
    }

    public function test_settings_report_each_engine_with_its_sources_and_weights(): void
    {
        $engines = $this->read('get-searchwp-settings')['engines'];
        $this->assertSame([ 'default' ], array_column($engines, 'name'));
        $this->assertSame('Default', $engines[0]['label']);
        $this->assertSame([ 'post.post', 'post.page' ], array_column($engines[0]['sources'], 'name'));
        $this->assertSame([ 'title' => 80, 'content' => 5, 'slug' => 60 ], $engines[0]['sources'][0]['attributes']);
        $this->assertTrue($engines[0]['settings']['stemming']);
    }

    public function test_index_status_counts_the_queue(): void
    {
        $status = $this->read('get-searchwp-index-status');
        $this->assertSame(4, $status['entries']);
        $this->assertSame(2, $status['indexed']);
        $this->assertSame(1, $status['queued']);
        $this->assertSame(1, $status['omitted']);
        $this->assertSame(1, $status['index_rows']);
        $this->assertSame([ 'post.post' => 4 ], $status['by_source']);
    }

    public function test_top_searches_are_aggregated_and_masked(): void
    {
        $top = $this->read('list-searchwp-top-searches');
        $this->assertSame('shoes', $top['searches'][0]['query']);
        $this->assertSame(2, $top['searches'][0]['searches']);
        $this->assertTrue($top['masked']);
        $this->assertStringNotContainsString('ada@example.test', (string) wp_json_encode($top));

        $zero = $this->read('list-searchwp-top-searches', [ 'zero_hits' => true ]);
        $this->assertNotContains('shoes', array_column($zero['searches'], 'query'));
    }

    public function test_rebuild_needs_confirm_and_is_not_undoable(): void
    {
        $preview = $this->write('rebuild-searchwp-index');
        $this->assertSame('confirmation_required', $preview['error']['code'] ?? null);
        $this->assertSame([], $this->rebuilds);

        $out = $this->write('rebuild-searchwp-index', [], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 1 ], $this->rebuilds);
        $this->assertFalse($out['recoverable'] ?? true);
    }

    public function test_engine_weights_write_is_opt_in_snapshotted_and_rolls_back(): void
    {
        $args = [ 'engine' => 'default', 'source' => 'post.post', 'attributes' => [ 'title' => 100, 'content' => 10 ] ];
        $this->assertSame('opt_in_required', $this->write('update-searchwp-engine', $args)['error']['code'] ?? null);

        add_filter(Search_Plugin_Support::ENABLE_FILTER, '__return_true');
        $before = get_option('searchwp_engines');
        $out    = $this->write('update-searchwp-engine', $args);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $after = get_option('searchwp_engines');
        $this->assertSame([ 'title' => 100, 'content' => 10, 'slug' => 60 ], $after['default']['sources']['post.post']['attributes']);
        $this->assertSame($before['default']['sources']['post.page'], $after['default']['sources']['post.page'], 'other sources stay');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('searchwp_engines'));
    }

    public function test_engine_write_refuses_unknown_engines_sources_attributes_and_bad_weights(): void
    {
        add_filter(Search_Plugin_Support::ENABLE_FILTER, '__return_true');
        $before = get_option('searchwp_engines');
        foreach ([
            [ [ 'engine' => 'nope', 'source' => 'post.post', 'attributes' => [ 'title' => 5 ] ], 'searchwp_engine_not_found' ],
            [ [ 'engine' => 'default', 'source' => 'post.product', 'attributes' => [ 'title' => 5 ] ], 'searchwp_source_not_found' ],
            [ [ 'engine' => 'default', 'source' => 'post.post', 'attributes' => [ 'excerpt' => 5 ] ], 'invalid_setting' ],
            [ [ 'engine' => 'default', 'source' => 'post.post', 'attributes' => [ 'title' => 0 ] ], 'invalid_setting' ],
            [ [ 'engine' => 'default', 'source' => 'post.post', 'attributes' => [ 'title' => 101 ] ], 'invalid_setting' ],
        ] as [ $args, $code ]) {
            $this->assertSame($code, $this->write('update-searchwp-engine', $args)['error']['code'] ?? null, (string) wp_json_encode($args));
        }
        $this->assertSame($before, get_option('searchwp_engines'));
    }
}
