<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Package_Tables_Snapshot;

/**
 * Issues #843 and #1147: the package-tables part of a safe update, in the
 * builds that ship the SQL importer. A dump taken before the update puts
 * the table's rows back. (The WooCommerce build's version, with no
 * importer, is checked in WooNarrowingTest.)
 */
final class PackageTablesSnapshotTest extends \WP_UnitTestCase
{
    private string $table = '';
    private string $dump  = '';

    protected function setUp(): void
    {
        global $wpdb;
        parent::setUp();
        $this->table = $wpdb->prefix . 'wpmcp1119_items';
        $this->dump  = get_temp_dir() . 'wpmcp-1119-' . wp_generate_password(6, false, false) . '.sql';
        // A real table: the dumper lists tables with SHOW TABLES, which
        // does not see the test suite's temporary ones.
        remove_filter('query', [ $this, '_create_temporary_tables' ]);
        remove_filter('query', [ $this, '_drop_temporary_tables' ]);
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$this->table}` (id INT PRIMARY KEY, label VARCHAR(20))");
        $wpdb->query("DELETE FROM `{$this->table}`");
        $wpdb->insert($this->table, [ 'id' => 1, 'label' => 'before' ]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$this->table}`");
        add_filter('query', [ $this, '_create_temporary_tables' ]);
        add_filter('query', [ $this, '_drop_temporary_tables' ]);
        if (is_file($this->dump)) {
            unlink($this->dump);
        }
        parent::tearDown();
    }

    public function test_a_dump_taken_before_the_update_puts_the_rows_back(): void
    {
        global $wpdb;
        $this->assertTrue(Package_Tables_Snapshot::available());

        Package_Tables_Snapshot::dump($this->dump, [ $this->table ]);
        $wpdb->update($this->table, [ 'label' => 'after' ], [ 'id' => 1 ]);
        $wpdb->insert($this->table, [ 'id' => 2, 'label' => 'new row' ]);

        $this->assertSame([], Package_Tables_Snapshot::replay($this->dump, [ $this->table ]));
        $this->assertSame([ [ 'id' => '1', 'label' => 'before' ] ], $wpdb->get_results("SELECT id, label FROM `{$this->table}` ORDER BY id", ARRAY_A));
    }
}
