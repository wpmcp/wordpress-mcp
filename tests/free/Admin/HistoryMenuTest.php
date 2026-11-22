<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\History_Page;
use WPMCP\Plugin;

/**
 * The History screen is the top-level wpmcp page and also its first submenu
 * entry. Both share the wpmcp slug and so the toplevel_page_wpmcp hook; when
 * each attached History_Page::render the screen printed twice and one Restore
 * click sent two restore requests.
 */
class HistoryMenuTest extends \WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        global $menu, $submenu, $_registered_pages, $admin_page_hooks;
        $menu              = [];
        $submenu           = [];
        $_registered_pages = [];
        $admin_page_hooks  = [];
        remove_all_actions('toplevel_page_wpmcp');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Plugin::instance()->register_admin_menu();
    }

    public function test_history_hook_has_exactly_one_render_callback(): void
    {
        global $wp_filter;

        $this->assertArrayHasKey('toplevel_page_wpmcp', $wp_filter);
        $callbacks = [];
        foreach ($wp_filter['toplevel_page_wpmcp']->callbacks as $by_priority) {
            foreach ($by_priority as $entry) {
                $callbacks[] = $entry['function'];
            }
        }

        $this->assertCount(1, $callbacks, 'the History page must be attached once');
        $this->assertIsArray($callbacks[0]);
        $this->assertInstanceOf(History_Page::class, $callbacks[0][0]);
        $this->assertSame('render', $callbacks[0][1]);
    }

    public function test_history_page_body_prints_once(): void
    {
        ob_start();
        do_action('toplevel_page_wpmcp');
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, '<div class="wrap">'), 'the History body must print once');
        $this->assertSame(1, substr_count($html, 'wpmcp_restore'), 'the Restore script must be emitted once');
    }

    public function test_first_submenu_entry_is_unchanged(): void
    {
        global $submenu;

        $this->assertArrayHasKey('wpmcp', $submenu);
        $first = $submenu['wpmcp'][0];
        $this->assertSame('wpmcp', $first[2], 'the first submenu entry is still the History screen');
        $this->assertSame(Plugin::BRAND, $first[0]);

        $history_entries = array_filter($submenu['wpmcp'], static fn ($item) => 'wpmcp' === $item[2]);
        $this->assertCount(1, $history_entries, 'no duplicate auto-created History entry');
    }
}
