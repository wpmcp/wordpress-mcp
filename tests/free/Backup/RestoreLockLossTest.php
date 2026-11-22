<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Restore_Lock_Lost;
use WPMCP\Tools\Backup\Restore_Site_Backup;

/**
 * Issue #1124: a restore whose database connection drops loses its restore
 * lock with it (MySQL frees a named lock with the connection), while wpdb
 * quietly reconnects and would carry on importing next to the recovery
 * restore. The worker checks it still holds the lock before every
 * statement and stops at once when it does not, writing nothing more: no
 * statement, no progress file, no rollback, no job history.
 */
class RestoreLockLossTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    /** @var \mysqli|null a second connection, standing in for another request */
    private $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if ($this->other instanceof \mysqli) {
            $this->other->close();
            $this->other = null;
        }
        // The killed connection is replaced by wpdb on its next query.
        $wpdb->check_connection(false);
        // A worker that lost its lock leaves the progress file for the
        // restore that holds the lock; here nobody does.
        $state = \WPMCP\Tools\Backup\Site_Backup_Dir::path() . '/restore-in-progress.json';
        if (is_file($state)) {
            unlink($state);
        }
        $this->clean_restore_fixtures();
        foreach (Backup_Job_Store::list() as $job) {
            $file = (string) ($job['result']['file'] ?? '');
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
        }
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        remove_all_actions('wpmcp_restore_statement_executed');
        parent::tearDown();
    }

    private function other_connection(): \mysqli
    {
        global $wpdb;
        [$host, $port, $socket] = $wpdb->parse_db_host(DB_HOST);
        $this->other = mysqli_init();
        $this->other->real_connect((string) $host, DB_USER, DB_PASSWORD, DB_NAME, null === $port ? null : (int) $port, null === $socket ? null : (string) $socket);
        return $this->other;
    }

    private function posts_tables(): array
    {
        global $wpdb;
        return [$wpdb->posts, $wpdb->postmeta];
    }

    private function restore(string $path): array
    {
        $tool = new Restore_Site_Backup($this->safety_producer($this->posts_tables()));
        return $tool->handle(['path' => $path, 'dry_run' => false]);
    }

    public function test_a_dropped_connection_stops_the_import_and_nothing_more_is_written(): void
    {
        global $wpdb;
        self::factory()->post->create_many(3);
        $archive = $this->archive_of($this->posts_tables());

        $other    = $this->other_connection();
        $executed = 0;
        $state    = null;
        add_action('wpmcp_restore_statement_executed', function () use (&$executed, &$state, $other, $wpdb): void {
            $executed++;
            if (2 === $executed) {
                // A server timeout or MySQL restart: the importing
                // connection is killed from outside.
                $other->query('KILL ' . (int) $wpdb->get_var('SELECT CONNECTION_ID()'));
                $state = Restore_Site_Backup::progress();
            }
        });

        try {
            $this->restore($archive);
            $this->fail('A restore that lost its lock must not report an outcome.');
        } catch (Restore_Lock_Lost $e) {
            $this->assertStringContainsString('lost', $e->getMessage());
        }

        $this->assertSame(2, $executed, 'No statement runs after the connection dropped.');
        $this->assertNotNull($state);
        $this->assertSame($state['state'], Restore_Site_Backup::progress()['state'], 'The progress file is left as it was, for whoever holds the lock now.');
    }

    public function test_while_another_restore_holds_the_lock_no_statement_reaches_the_database(): void
    {
        global $wpdb;
        self::factory()->post->create_many(3);
        $archive  = $this->archive_of($this->posts_tables());
        $other    = $this->other_connection();
        $lock     = (string) $wpdb->get_var("SELECT CONCAT('wpmcp_restore_', SUBSTRING(MD5(CONCAT('" . esc_sql(DB_NAME) . "', '|', '" . esc_sql($wpdb->base_prefix) . "')), 1, 16))");
        $executed = 0;
        add_action('wpmcp_restore_statement_executed', function () use (&$executed, $other, $wpdb, $lock): void {
            $executed++;
            if (1 === $executed) {
                // The lock went with a reconnect, and the recovery restore
                // took it.
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                $other->query("SELECT GET_LOCK('" . $other->real_escape_string($lock) . "', 0)");
                // What the recovery restore's import leaves in the store.
                update_option(Backup_Job_Store::OPTION, ['marker' => 'recovery restore']);
            }
        });

        $this->expectException(Restore_Lock_Lost::class);
        try {
            $this->restore($archive);
        } finally {
            $this->assertSame(1, $executed);
            $this->assertSame(['marker' => 'recovery restore'], get_option(Backup_Job_Store::OPTION), 'The job history is not rewritten.');
            $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($lock) . "')");
        }
    }

    public function test_the_recommended_recovery_restore_then_completes_alone(): void
    {
        global $wpdb;
        $post    = self::factory()->post->create(['post_title' => 'Recovered']);
        $archive = $this->archive_of($this->posts_tables());

        $other    = $this->other_connection();
        $executed = 0;
        add_action('wpmcp_restore_statement_executed', function () use (&$executed, $other, $wpdb): void {
            $executed++;
            if (2 === $executed) {
                $other->query('KILL ' . (int) $wpdb->get_var('SELECT CONNECTION_ID()'));
            }
        });
        try {
            $this->restore($archive);
        } catch (Restore_Lock_Lost $e) {
            unset($e);
        }
        remove_all_actions('wpmcp_restore_statement_executed');
        $wpdb->check_connection(false);

        $out = $this->restore($archive);

        $this->assertTrue($out['restored']);
        $this->assertSame('restored', $out['status']);
        clean_post_cache($post);
        $this->assertSame('Recovered', get_post($post)->post_title);
        $this->assertNull(Restore_Site_Backup::progress());
    }
}
