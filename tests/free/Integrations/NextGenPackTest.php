<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\NextGen_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * NextGEN Gallery (issue #705): free-tier ops on the plugin-data pair.
 *
 * NextGEN is not loaded in the shared test core (NextGenLiveTest runs the
 * ops against the real plugin). Presence is driven through
 * wpmcp_nextgen_active, and its two tables are built here column for
 * column as NextGEN Gallery 4.6 installs them (src/Legacy/admin/install.php):
 * ngg_gallery (gid, name, slug, path, title, galdesc, author) and
 * ngg_pictures (pid, galleryid, filename, alttext, description, exclude,
 * sortorder). Its capabilities are its own ("NextGEN Manage gallery" and
 * the rest, src/Util/Installer.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_nextgen_write)
 * and each is undone in a test.
 */
final class NextGenPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'nextgen-list-galleries' => 'read',
        'nextgen-get-gallery'    => 'read',
        'nextgen-reorder-images' => 'write',
        'nextgen-update-image'   => 'write',
    ];

    private const CAPS = [ 'NextGEN Gallery overview', 'NextGEN Manage gallery', 'NextGEN Manage others gallery' ];

    /** DDL commits implicitly, so the tables are built once for the class. */
    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        if (defined('NGG_PLUGIN_VERSION')) {
            return;
        }
        $p = $wpdb->prefix;
        self::drop();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}ngg_pictures (
            pid BIGINT(20) NOT NULL AUTO_INCREMENT, image_slug VARCHAR(255) NOT NULL, post_id BIGINT(20) DEFAULT '0' NOT NULL,
            galleryid BIGINT(20) DEFAULT '0' NOT NULL, filename VARCHAR(255) NOT NULL, description MEDIUMTEXT NULL, alttext MEDIUMTEXT NULL,
            imagedate DATETIME NOT NULL DEFAULT '2026-01-01 00:00:00', exclude TINYINT NULL DEFAULT '0', sortorder BIGINT(20) DEFAULT '0' NOT NULL,
            meta_data LONGTEXT, extras_post_id BIGINT(20) DEFAULT '0' NOT NULL, PRIMARY KEY (pid))");
        $wpdb->query("CREATE TABLE {$p}ngg_gallery (
            gid BIGINT(20) NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, path MEDIUMTEXT NULL,
            title MEDIUMTEXT NULL, galdesc MEDIUMTEXT NULL, pageid BIGINT(20) DEFAULT '0' NOT NULL, previewpic BIGINT(20) DEFAULT '0' NOT NULL,
            author BIGINT(20) DEFAULT '0' NOT NULL, extras_post_id BIGINT(20) DEFAULT '0' NOT NULL, date_created DATETIME NULL,
            date_modified DATETIME NULL, PRIMARY KEY (gid))");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        if (! defined('NGG_PLUGIN_VERSION')) {
            self::drop();
        }
    }

    private static function drop(): void
    {
        global $wpdb;
        foreach ([ 'ngg_pictures', 'ngg_gallery' ] as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_nextgen_active', '__return_true');
        add_filter('wpmcp_enable_nextgen_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_nextgen_active');
        remove_all_filters('wpmcp_enable_nextgen_write');
        if (! defined('NGG_PLUGIN_VERSION')) {
            $role = get_role('administrator');
            foreach (self::CAPS as $cap) {
                $role->remove_cap($cap);
            }
        }
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
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

    private function gallery(string $title = 'Summer', int $author = 0): int
    {
        global $wpdb;
        $slug = sanitize_title($title);
        $wpdb->insert($wpdb->prefix . 'ngg_gallery', [ 'name' => $slug, 'slug' => $slug, 'path' => 'wp-content/gallery/' . $slug . '/', 'title' => $title, 'galdesc' => '', 'author' => $author ?: get_current_user_id() ]);
        return (int) $wpdb->insert_id;
    }

    private function picture(int $gallery, string $file, int $sortorder): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ngg_pictures', [ 'image_slug' => sanitize_title($file), 'galleryid' => $gallery, 'filename' => $file, 'alttext' => pathinfo($file, PATHINFO_FILENAME), 'description' => '', 'sortorder' => $sortorder, 'meta_data' => '' ]);
        return (int) $wpdb->insert_id;
    }

    private function order(int $gallery): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT pid FROM %i WHERE galleryid = %d ORDER BY sortorder, pid', $wpdb->prefix . 'ngg_pictures', $gallery)));
    }

    private function row(int $pid): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE pid = %d', $wpdb->prefix . 'ngg_pictures', $pid), ARRAY_A);
    }

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_nextgen_is_missing_a_call_answers_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_nextgen_active');
        add_filter('wpmcp_nextgen_active', '__return_false');
        $out = $this->read('nextgen-list-galleries');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate NextGEN Gallery', $out['error']['message']);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_nextgen_active');
        $this->assertSame(defined('NGG_PLUGIN_VERSION'), NextGen_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $gallery = $this->gallery();
        $pic     = $this->picture($gallery, 'one.jpg', 1);
        remove_all_filters('wpmcp_enable_nextgen_write');
        foreach ([
            [ 'nextgen-reorder-images', [ 'id' => $gallery, 'image_ids' => [ $pic ] ] ],
            [ 'nextgen-update-image', [ 'image_id' => $pic, 'alttext' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_nextgen_write', $out['error']['message']);
        }
        $this->assertSame('one', $this->row($pic)['alttext']);
    }

    public function test_nextgens_own_capabilities_and_ownership_are_required(): void
    {
        $mine   = $this->gallery('Mine');
        $theirs = $this->gallery('Theirs', self::factory()->user->create());
        $a      = $this->picture($mine, 'a.jpg', 1);
        $b      = $this->picture($theirs, 'b.jpg', 1);
        $denied = [];
        $filter = static function (array $caps) use (&$denied): array {
            foreach ($denied as $cap) {
                $caps[ $cap ] = false;
            }
            return $caps;
        };
        add_filter('user_has_cap', $filter);
        try {
            $denied = [ 'NextGEN Manage others gallery' ];
            $this->ok($this->write('nextgen-update-image', [ 'image_id' => $a, 'alttext' => 'Own' ]));
            $this->assertSame('operation_denied', $this->write('nextgen-update-image', [ 'image_id' => $b, 'alttext' => 'x' ])['error']['code'] ?? null, 'another user\'s gallery needs Manage others gallery');
            $denied = [ 'NextGEN Manage gallery' ];
            $this->assertSame('operation_denied', $this->write('nextgen-update-image', [ 'image_id' => $a, 'alttext' => 'x' ])['error']['code'] ?? null);
            $denied = [ 'NextGEN Gallery overview' ];
            $this->assertSame('operation_denied', $this->read('nextgen-list-galleries')['error']['code'] ?? null);
        } finally {
            remove_filter('user_has_cap', $filter);
        }
        $this->assertSame('b', $this->row($b)['alttext']);
    }

    public function test_list_and_get_return_galleries_and_images_in_stored_order(): void
    {
        $a      = $this->gallery('Alpha');
        $b      = $this->gallery('Beta');
        $second = $this->picture($a, 'second.jpg', 2);
        $first  = $this->picture($a, 'first.jpg', 1);

        $out  = $this->ok($this->read('nextgen-list-galleries'));
        $rows = array_column($out['galleries'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame(2, $rows[ $a ]['image_count']);
        $this->assertSame(0, $rows[ $b ]['image_count']);
        $this->assertSame([ $a ], array_column($this->read('nextgen-list-galleries', [ 'search' => 'Alph' ])['galleries'], 'id'));

        $g = $this->ok($this->read('nextgen-get-gallery', [ 'id' => $a ]))['gallery'];
        $this->assertSame([ $first, $second ], array_column($g['images'], 'id'));
        $this->assertSame(site_url('/wp-content/gallery/alpha/first.jpg'), $g['images'][0]['url']);
        $this->assertFalse($g['images'][0]['excluded']);
        $this->assertSame('gallery_not_found', $this->read('nextgen-get-gallery', [ 'id' => 99999 ])['error']['code'] ?? null);
    }

    public function test_reorder_images_needs_exactly_the_current_images_and_rolls_back(): void
    {
        $gallery = $this->gallery();
        $a       = $this->picture($gallery, 'a.jpg', 1);
        $b       = $this->picture($gallery, 'b.jpg', 2);
        $c       = $this->picture($gallery, 'c.jpg', 3);
        $other   = $this->picture($this->gallery('Other'), 'x.jpg', 1);

        $this->assertSame('images_mismatch', $this->write('nextgen-reorder-images', [ 'id' => $gallery, 'image_ids' => [ $c, $a ] ])['error']['code'] ?? null);
        $this->assertSame('images_mismatch', $this->write('nextgen-reorder-images', [ 'id' => $gallery, 'image_ids' => [ $c, $a, $other ] ])['error']['code'] ?? null);

        $out = $this->ok($this->write('nextgen-reorder-images', [ 'id' => $gallery, 'image_ids' => [ $c, $a, $b ] ]));
        $this->assertSame([ $c, $a, $b ], $this->order($gallery));
        $this->assertSame(1, (int) $this->row($other)['sortorder'], 'another gallery is left alone');
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([ $a, $b, $c ], $this->order($gallery));
    }

    public function test_update_image_changes_alttext_description_and_exclude_and_rolls_back(): void
    {
        $gallery = $this->gallery();
        $pic     = $this->picture($gallery, 'pier.jpg', 1);

        $out = $this->ok($this->write('nextgen-update-image', [ 'image_id' => $pic, 'alttext' => 'The pier', 'description' => 'At dusk', 'excluded' => true ]));
        $this->assertTrue($out['recoverable']);
        $row = $this->row($pic);
        $this->assertSame('The pier', $row['alttext']);
        $this->assertSame('At dusk', $row['description']);
        $this->assertSame('1', (string) $row['exclude']);
        $this->assertTrue($out['result']['image']['excluded']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $row = $this->row($pic);
        $this->assertSame('pier', $row['alttext']);
        $this->assertSame('', $row['description']);
        $this->assertSame('0', (string) $row['exclude']);
        $this->assertSame('image_not_found', $this->write('nextgen-update-image', [ 'image_id' => 99999, 'alttext' => 'x' ])['error']['code'] ?? null);
    }
}
