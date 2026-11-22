<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Pro_Banner;
use WPMCP\Admin\Star_Prompt;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * The GitHub star prompt: a one-time, dismissible notice on the History and
 * Connection screens, shown once the site has made its 3rd agent write or
 * its first rollback, whichever comes first. Never with a Pro licence, never
 * on the same screen load as the Pro banner, and switchable off by filter.
 */
class StarPromptTest extends \WP_UnitTestCase
{
    private int $admin_id;

    private const HISTORY    = 'toplevel_page_wpmcp';
    private const CONNECTION = 'wpmcp_page_wpmcp-connection';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        delete_option(Star_Prompt::READY_OPTION);

        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $this->hide_pro_banner_for($this->admin_id);
        set_current_screen(self::HISTORY);
        Gate::set_pro_for_tests(false);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        unset($_GET['_wpnonce'], $_GET['redirect_to']);
        remove_all_filters('wpmcp_star_prompt_enabled');
        delete_option(Star_Prompt::READY_OPTION);
        parent::tearDown();
    }

    /** The Pro banner shares these screens; most tests need it out of the way. */
    private function hide_pro_banner_for(int $user_id): void
    {
        update_user_meta($user_id, Pro_Banner::META_KEY, ['dismissed_at' => time(), 'dismissals' => Pro_Banner::MAX_DISMISSALS]);
    }

    private function writes(int $count, bool $rolled_back = false): void
    {
        global $wpdb;
        for ($i = 0; $i < $count; $i++) {
            $op = wp_generate_uuid4();
            Snapshot_Store::save($op, 'sess', ['object_type' => 'post', 'object_id' => $i + 1, 'data' => ['post' => null, 'meta' => []]], 'update-post', str_repeat('a', 64));
            if ($rolled_back) {
                $wpdb->query($wpdb->prepare('UPDATE %i SET rolled_back_at = %s WHERE operation_id = %s', Snapshot_Store::table_name(), current_time('mysql', true), $op));
            }
        }
    }

    private function rendered(): string
    {
        ob_start();
        (new Star_Prompt())->render();
        return (string) ob_get_clean();
    }

    private function dismiss_with_nonce(string $redirect_to = ''): ?string
    {
        $_GET['_wpnonce'] = wp_create_nonce(Star_Prompt::NONCE_ACTION);
        if ('' !== $redirect_to) {
            $_GET['redirect_to'] = rawurlencode($redirect_to);
        }
        $captured = null;
        $result   = (new Star_Prompt())->handle_dismiss(static function (string $url) use (&$captured): void {
            $captured = $url;
        });
        $this->assertNull($result, 'a valid dismissal must not return an error');
        return $captured;
    }

    public function test_hidden_before_the_third_write(): void
    {
        $this->assertSame('', $this->rendered(), 'no writes yet');

        $this->writes(2);
        $this->assertSame('', $this->rendered(), 'two writes are not enough');
    }

    public function test_shown_after_the_third_write_with_the_repo_link(): void
    {
        $this->writes(3);

        $html = $this->rendered();
        $this->assertStringContainsString('wpmcp-star-prompt', $html);
        $this->assertStringContainsString('WP MCP just kept a snapshot of your site', $html);
        $this->assertStringContainsString('href="' . Star_Prompt::REPO_URL . '"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('action=' . Star_Prompt::DISMISS_ACTION, $html);
        $this->assertStringContainsString('_wpnonce=', $html);
        $this->assertStringContainsString('class="notice', $html, 'it is a standard WordPress admin notice');
    }

    public function test_shown_after_the_first_rollback_even_with_one_write(): void
    {
        $this->writes(1, true);

        $this->assertNotSame('', $this->rendered());
    }

    public function test_shown_on_history_and_connection_only(): void
    {
        $this->writes(3);
        $expectations = [
            self::HISTORY                => true,
            self::CONNECTION             => true,
            'dashboard'                  => false,
            'plugins'                    => false,
            'wpmcp_page_wpmcp-audit-log' => false,
            'edit-post'                  => false,
            'options-general'            => false,
        ];

        foreach ($expectations as $screen => $shown) {
            set_current_screen($screen);
            $this->assertSame($shown, '' !== $this->rendered(), "screen {$screen}: expected " . ($shown ? 'shown' : 'hidden'));
        }
    }

    /** Core names submenu screens after the sanitized menu title, which can carry a badge. */
    public function test_follows_the_real_connection_screen_id_while_the_menu_carries_a_badge(): void
    {
        $this->writes(3);
        $saved = $GLOBALS['admin_page_hooks']['wpmcp'] ?? null;
        $GLOBALS['admin_page_hooks']['wpmcp'] = 'wpmcp-3';

        try {
            set_current_screen('wpmcp-3_page_wpmcp-connection');
            $this->assertNotSame('', $this->rendered());
        } finally {
            if (null === $saved) {
                unset($GLOBALS['admin_page_hooks']['wpmcp']);
            } else {
                $GLOBALS['admin_page_hooks']['wpmcp'] = $saved;
            }
        }
    }

    public function test_hidden_with_an_active_pro_licence(): void
    {
        $this->writes(3);
        Gate::set_pro_for_tests(true);

        $this->assertSame('', $this->rendered());
    }

    public function test_never_on_the_same_screen_as_the_pro_banner(): void
    {
        $this->writes(3);
        delete_user_meta($this->admin_id, Pro_Banner::META_KEY);

        $this->assertNotSame('', (function (): string {
            ob_start();
            (new Pro_Banner())->render();
            return (string) ob_get_clean();
        })(), 'precondition: the Pro banner shows here');
        $this->assertSame('', $this->rendered(), 'the star prompt yields to the Pro banner');

        $this->hide_pro_banner_for($this->admin_id);
        $this->assertNotSame('', $this->rendered(), 'and shows once the Pro banner is out of the way');
    }

    public function test_hidden_below_the_management_capability(): void
    {
        $this->writes(3);
        $editor = self::factory()->user->create(['role' => 'editor']);
        $this->hide_pro_banner_for($editor);
        wp_set_current_user($editor);

        $this->assertSame('', $this->rendered());
    }

    public function test_the_filter_turns_it_off(): void
    {
        $this->writes(3);
        add_filter('wpmcp_star_prompt_enabled', '__return_false');

        $this->assertSame('', $this->rendered());
    }

    public function test_once_earned_it_survives_a_pruned_history(): void
    {
        $this->writes(3);
        $this->assertNotSame('', $this->rendered());

        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        $this->assertNotSame('', $this->rendered(), 'the trigger is latched, not recounted from a pruned ledger');
    }

    public function test_one_dismissal_hides_it_for_good_per_user(): void
    {
        $this->writes(3);

        $redirect = $this->dismiss_with_nonce('http://example.org/wp-admin/admin.php?page=wpmcp');
        $this->assertSame('http://example.org/wp-admin/admin.php?page=wpmcp', $redirect);
        $this->assertSame('', $this->rendered(), 'hidden after one dismissal');

        $this->writes(5, true);
        $this->assertSame('', $this->rendered(), 'later writes and rollbacks do not bring it back');

        $other = self::factory()->user->create(['role' => 'administrator']);
        $this->hide_pro_banner_for($other);
        wp_set_current_user($other);
        $this->assertNotSame('', $this->rendered(), 'another administrator still sees it once');
    }

    public function test_dismiss_refuses_a_missing_nonce(): void
    {
        $this->writes(3);
        $result = (new Star_Prompt())->handle_dismiss(static function (): void {
        });

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_forbidden', $result->get_error_code());
        $this->assertNotSame('', $this->rendered(), 'a refused dismissal changes nothing');
    }

    public function test_dismiss_refuses_a_user_below_the_management_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $_GET['_wpnonce'] = wp_create_nonce(Star_Prompt::NONCE_ACTION);

        $result = (new Star_Prompt())->handle_dismiss(static function (): void {
        });

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_copy_is_plain_and_has_no_dashes(): void
    {
        $this->writes(3);
        $html = strtolower($this->rendered());

        foreach (['the only', 'every write', 'upgrade', 'pro feature'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html, "phrase present: {$phrase}");
        }
        $this->assertStringNotContainsString("\u{2014}", $html, 'em dash'); // dash-guard-ignore: the test looks for the character
        $this->assertStringNotContainsString("\u{2013}", $html, 'en dash'); // dash-guard-ignore: the test looks for the character
    }

    public function test_registers_its_notice_and_dismiss_handler(): void
    {
        $prompt = Star_Prompt::register();

        $this->assertNotFalse(has_action('admin_notices', [$prompt, 'render']));
        $this->assertNotFalse(has_action('admin_post_' . Star_Prompt::DISMISS_ACTION));
        remove_action('admin_notices', [$prompt, 'render']);
    }
}
