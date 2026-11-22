<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1091: Depicter writes on the plugin-data pair, beside the reads of
 * #898. Depicter 4 keeps a document as a depicter_documents row (parent 0)
 * and each published checkpoint as a revision row whose parent is the
 * document (DocumentRepository::saveEditorData() adds one on publish and
 * sets every revision to draft on unpublish); display rules are the JSON
 * `rules` row of depicter_meta (relation document).
 */
class DepicterWritesTest extends \WP_UnitTestCase
{
    private const TABLES = [ 'depicter_documents', 'depicter_meta' ];

    private int $doc = 0;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::drop();
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}depicter_documents (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name text NOT NULL, slug varchar(100) NOT NULL, type varchar(50) NOT NULL DEFAULT 'custom', author bigint(20) unsigned NOT NULL DEFAULT '0', sections_count mediumint NOT NULL DEFAULT '0', created_at datetime DEFAULT NULL, modified_at datetime DEFAULT NULL, thumbnail varchar(255) NOT NULL, content longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft', parent bigint(20) unsigned NOT NULL DEFAULT '0', password varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (id))");
        $wpdb->query("CREATE TABLE {$p}depicter_meta (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, relation varchar(30) NOT NULL DEFAULT '', relation_id bigint(20) NOT NULL, meta_key varchar(30) NOT NULL DEFAULT '', meta_value text NOT NULL, PRIMARY KEY (id))");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop();
    }

    private static function drop(): void
    {
        global $wpdb;
        foreach (self::TABLES as $t) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $t));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Snapshot_Store::install();
        foreach (self::TABLES as $t) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . $t));
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_depicter_active', '__return_true');
        add_filter('wpmcp_enable_depicter_write', '__return_true');
        $wpdb->insert($wpdb->prefix . 'depicter_documents', [ 'name' => 'Hero', 'slug' => 'hero', 'type' => 'popup', 'author' => 1, 'sections_count' => 2, 'created_at' => '2026-11-01 10:00:00', 'modified_at' => '2026-11-02 10:00:00', 'thumbnail' => '', 'content' => '{"sections":{}}', 'status' => 'draft', 'parent' => 0, 'password' => '' ]);
        $this->doc = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'depicter_documents', [ 'name' => 'Hero', 'slug' => 'hero', 'type' => 'popup', 'author' => 1, 'sections_count' => 2, 'created_at' => '2026-10-01 10:00:00', 'modified_at' => '2026-10-01 10:00:00', 'thumbnail' => '', 'content' => '{"sections":{}}', 'status' => 'draft', 'parent' => $this->doc, 'password' => '' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_depicter_active');
        remove_all_filters('wpmcp_enable_depicter_write');
        parent::tearDown();
    }

    /** @param array<string, mixed> $args */
    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'depicter_documents', $id), ARRAY_A);
    }

    /** @return list<string> */
    private function revision_statuses(): array
    {
        global $wpdb;
        return array_map('strval', $wpdb->get_col($wpdb->prepare('SELECT status FROM %i WHERE parent = %d ORDER BY id', $wpdb->prefix . 'depicter_documents', $this->doc)));
    }

    private function rules(): ?string
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM %i WHERE relation = 'document' AND relation_id = %d AND meta_key = 'rules'", $wpdb->prefix . 'depicter_meta', $this->doc));
        return null === $raw ? null : (string) $raw;
    }

    public function test_the_writes_are_catalogued_and_off_until_the_site_opts_in(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'depicter-rename-document' => 'write', 'depicter-set-status' => 'write', 'depicter-set-display-rules' => 'write' ] as $op => $mode) {
            $this->assertSame($mode, $ops[ $op ]['mode'] ?? null, $op);
        }
        remove_all_filters('wpmcp_enable_depicter_write');
        $this->assertSame('opt_in_required', $this->write('depicter-rename-document', [ 'id' => $this->doc, 'name' => 'X' ])['error']['code'] ?? null);
    }

    public function test_rename_rolls_back(): void
    {
        $out = $this->write('depicter-rename-document', [ 'id' => $this->doc, 'name' => 'Winter hero' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('Winter hero', $this->row($this->doc)['name']);
        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('Hero', $this->row($this->doc)['name']);
    }

    public function test_publish_adds_a_revision_and_one_session_takes_it_away_again(): void
    {
        $out = $this->write('depicter-set-status', [ 'id' => $this->doc, 'status' => 'publish' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('publish', $this->row($this->doc)['status']);
        $this->assertSame([ 'draft', 'publish' ], $this->revision_statuses(), 'a published revision is added, as Depicter does');

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame('draft', $this->row($this->doc)['status']);
        $this->assertSame([ 'draft' ], $this->revision_statuses(), 'the added revision goes');
    }

    public function test_unpublish_sets_the_document_and_its_revisions_to_draft_and_rolls_back(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'depicter_documents', [ 'status' => 'publish' ], [ 'id' => $this->doc ]);
        $wpdb->update($wpdb->prefix . 'depicter_documents', [ 'status' => 'publish' ], [ 'parent' => $this->doc ]);

        $out = $this->write('depicter-set-status', [ 'id' => $this->doc, 'status' => 'draft' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('draft', $this->row($this->doc)['status']);
        $this->assertSame([ 'draft' ], $this->revision_statuses());

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame('publish', $this->row($this->doc)['status']);
        $this->assertSame([ 'publish' ], $this->revision_statuses());
    }

    public function test_display_rules_are_written_and_a_created_rules_row_goes_on_undo(): void
    {
        $rules = [ 'visibility' => [ 'pages' => [ 'include' => [ 12 ] ] ] ];
        $out   = $this->write('depicter-set-display-rules', [ 'id' => $this->doc, 'rules' => $rules ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame($rules, json_decode((string) $this->rules(), true));

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertNull($this->rules(), 'the rules row the write created is removed');
    }

    public function test_a_revision_or_unknown_id_is_refused(): void
    {
        global $wpdb;
        $revision = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE parent = %d', $wpdb->prefix . 'depicter_documents', $this->doc));
        $this->assertSame('document_not_found', $this->write('depicter-rename-document', [ 'id' => $revision, 'name' => 'X' ])['error']['code'] ?? null);
        $this->assertSame('document_not_found', $this->write('depicter-set-status', [ 'id' => 999999, 'status' => 'publish' ])['error']['code'] ?? null);
    }
}
