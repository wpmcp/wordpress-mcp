<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #1125: the Mutations and Requests tabs page like a core list table
 * (first, previous, "N of M", next, last buttons, top and bottom, filters
 * kept) and stripe their rows.
 */
class AuditLogCorePagingTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        $_GET = [];
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Request_Log::set_clock_for_tests(null);
        delete_option(Request_Log::OPTION);
        parent::tearDown();
    }

    private function seed_mutations(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Snapshot_Store::save('op-' . $i, 'sess', [ 'object_type' => 'post', 'object_id' => $i, 'data' => [ 'post' => null, 'meta' => [] ] ], 'update-post', str_repeat('a', 64));
        }
    }

    private function seed_requests(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Request_Log::set_clock_for_tests(1700000000 + $i);
            Request_Log::record([ 'tool' => 'req-' . $i, 'ok' => true ]);
        }
    }

    private function render(): string
    {
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    private function assert_core_paging(string $html, int $current, int $total): void
    {
        $this->assertSame(2, substr_count($html, 'class="pagination-links"'), 'top and bottom');
        $this->assertStringNotContainsString('page-numbers', $html, 'no bare paginate_links() numbers');
        // First, previous, next and last on each of the two navs: a link
        // button, or a disabled span where it would go nowhere.
        $controls = substr_count($html, 'tablenav-pages-navspan button disabled');
        foreach ([ 'first-page', 'prev-page', 'next-page', 'last-page' ] as $button) {
            $controls += substr_count($html, 'class="' . $button . ' button"');
        }
        $this->assertSame(8, $controls);
        $this->assertSame(2, substr_count($html, sprintf('%d of <span class="total-pages">%d</span>', $current, $total)));
    }

    public function test_mutations_tab_has_core_paging_buttons_and_striped_rows(): void
    {
        $this->seed_mutations(45);
        $_GET['paged'] = '2';

        $html = $this->render();

        $this->assert_core_paging($html, 2, 3);
        $this->assertMatchesRegularExpression('#class="first-page button" href="[^"]*paged=1#', $html);
        $this->assertMatchesRegularExpression('#class="prev-page button" href="[^"]*paged=1#', $html);
        $this->assertMatchesRegularExpression('#class="next-page button" href="[^"]*paged=3#', $html);
        $this->assertMatchesRegularExpression('#class="last-page button" href="[^"]*paged=3#', $html);
        $this->assertStringContainsString('<table class="widefat striped">', $html);
    }

    public function test_first_page_disables_first_and_previous(): void
    {
        $this->seed_mutations(45);

        $html = $this->render();

        $this->assertStringNotContainsString('class="first-page button"', $html);
        $this->assertStringNotContainsString('class="prev-page button"', $html);
        $this->assertSame(4, substr_count($html, 'class="tablenav-pages-navspan button disabled"'));
    }

    public function test_paging_keeps_the_filters(): void
    {
        $this->seed_mutations(45);
        $_GET['tool_name'] = 'update-post';

        $html = $this->render();

        $this->assertMatchesRegularExpression('#class="next-page button" href="[^"]*tool_name=update-post[^"]*"#', $html);
    }

    public function test_requests_tab_has_core_paging_buttons_and_striped_rows(): void
    {
        $this->seed_requests(45);
        $_GET['tab']     = 'requests';
        $_GET['outcome'] = 'ok';
        $_GET['paged']   = '3';

        $html = $this->render();

        $this->assert_core_paging($html, 3, 3);
        $this->assertMatchesRegularExpression('#class="prev-page button" href="[^"]*tab=requests[^"]*"#', $html);
        $this->assertMatchesRegularExpression('#class="prev-page button" href="[^"]*outcome=ok[^"]*"#', $html);
        $this->assertStringNotContainsString('class="next-page button"', $html);
        $this->assertStringContainsString('<table class="widefat striped">', $html);
    }
}
