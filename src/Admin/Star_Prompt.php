<?php

namespace WPMCP\Admin;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A one-time GitHub star prompt on the History and Connection screens.
 *
 * It waits until the plugin has done its job on this site: the 3rd agent
 * write it took an undo point for, or the first rollback, whichever comes
 * first. Once earned the moment is latched in an option, so pruning the free
 * history back to its cap never takes it away again.
 *
 * Deliberately narrower than Pro_Banner, whose patterns it reuses:
 *  - users with the management capability only, the same gate as the
 *    dismiss handler, so it is shown only to users who can dismiss it;
 *  - the History and Connection screens only;
 *  - never with a Pro licence, and never on a screen load where the Pro
 *    banner renders, so a free install never sees two WP MCP banners at once;
 *  - one dismissal hides it for good, per user;
 *  - the wpmcp_star_prompt_enabled filter turns it off for the whole site.
 *
 * The directory flavors strip this file with the Pro banner
 * (scripts/flavors/wporg/policy.php): the first directory submissions ship
 * no promotional notices (issue #1140, decision D5).
 */
class Star_Prompt
{
    /** User meta: the time the user dismissed the prompt. Its presence hides it. */
    public const META_KEY = 'wpmcp_star_prompt_dismissed';

    /** Site option: the time the site earned the prompt. */
    public const READY_OPTION = 'wpmcp_star_prompt_ready';

    public const DISMISS_ACTION = 'wpmcp_dismiss_star_prompt';
    public const NONCE_ACTION   = 'wpmcp_dismiss_star_prompt';

    public const REPO_URL = 'https://github.com/wpmcp/wordpress-mcp';

    /** Agent writes with an undo point before the prompt is earned. */
    public const WRITES_NEEDED = 3;

    /** Screen id of the History screen (the top-level WP MCP page). */
    private const HISTORY_SCREEN = 'toplevel_page_wpmcp';

    /** Submenu slug of the Connection screen. */
    private const CONNECTION_SLUG = 'wpmcp-connection';

    /**
     * Hooks the notice and the dismiss handler. Both callbacks self-gate, so
     * registration is always safe.
     */
    public static function register(): self
    {
        $instance = new self();
        add_action('admin_notices', [$instance, 'render']);
        Management_Access::add_handler('admin_post_' . self::DISMISS_ACTION, [$instance, 'handle_dismiss']);

        return $instance;
    }

    /**
     * History is the top-level page. Connection is a submenu, whose screen id
     * core builds from the parent's sanitized menu title, which carries the
     * pending-proposal badge (see Pro_Banner::screen_allowed()), so the real
     * prefix is read back from $admin_page_hooks with the plain one as the
     * fallback.
     */
    public static function screen_allowed(string $screen_id): bool
    {
        if (self::HISTORY_SCREEN === $screen_id) {
            return true;
        }

        $hook     = $GLOBALS['admin_page_hooks']['wpmcp'] ?? '';
        $prefixes = ['wpmcp'];
        if (is_string($hook) && '' !== $hook) {
            $prefixes[] = $hook;
        }
        foreach ($prefixes as $prefix) {
            if ($prefix . '_page_' . self::CONNECTION_SLUG === $screen_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the site has reached the moment: WRITES_NEEDED operations in
     * the undo ledger, or one rolled back. Latched on the first yes.
     */
    public static function earned(): bool
    {
        if (get_option(self::READY_OPTION)) {
            return true;
        }

        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpmcp_snapshots is this plugin's own ledger; the count must be live, and the answer is latched in an option on the first yes.
        $row = $wpdb->get_row($wpdb->prepare('SELECT COUNT(DISTINCT operation_id) AS ops, MAX(rolled_back_at IS NOT NULL) AS rolled FROM %i', Snapshot_Store::table_name()), ARRAY_A);
        $wpdb->suppress_errors($suppressed);

        if (! is_array($row)) {
            return false;
        }
        if ((int) ($row['ops'] ?? 0) < self::WRITES_NEEDED && 1 !== (int) ($row['rolled'] ?? 0)) {
            return false;
        }

        update_option(self::READY_OPTION, time(), false);

        return true;
    }

    /** The rules, with the capability left to render(). */
    public function should_show(int $user_id, string $screen_id): bool
    {
        if (! (bool) apply_filters('wpmcp_star_prompt_enabled', true)) {
            return false;
        }
        if (Gate::is_pro() || ! self::screen_allowed($screen_id)) {
            return false;
        }
        if ($user_id <= 0 || '' !== (string) get_user_meta($user_id, self::META_KEY, true)) {
            return false;
        }
        if ((new Pro_Banner())->should_show($user_id, $screen_id)) {
            return false;
        }

        return self::earned();
    }

    /** Hides the prompt for good for one user. */
    public function dismiss(int $user_id): void
    {
        if ($user_id > 0) {
            update_user_meta($user_id, self::META_KEY, (string) time());
        }
    }

    /** admin_notices callback. */
    public function render(): void
    {
        if (! current_user_can(Management_Access::CAPABILITY) || ! function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (null === $screen || ! $this->should_show(get_current_user_id(), (string) $screen->id)) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'      => self::DISMISS_ACTION,
                    'redirect_to' => rawurlencode(self::current_url()),
                ],
                admin_url('admin-post.php')
            ),
            self::NONCE_ACTION
        );
        ?>
        <div class="notice notice-success wpmcp-star-prompt">
            <p>
                <strong><?php esc_html_e('WP MCP just kept a snapshot of your site.', 'wpmcp'); ?></strong>
                <?php esc_html_e('If the free plugin helps, a star on GitHub helps other WordPress builders find it.', 'wpmcp'); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(self::REPO_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Star WP MCP on GitHub', 'wpmcp'); ?></a>
                &nbsp;
                <a href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('Do not show this again', 'wpmcp'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * admin_post handler for the dismissal: management capability + nonce,
     * then a redirect back to the referring screen.
     *
     * @param callable|null $redirector Test seam: receives the redirect URL
     *                                  instead of wp_safe_redirect + exit.
     * @return \WP_Error|null WP_Error on refusal when a $redirector is
     *                        injected; otherwise redirects and exits.
     */
    public function handle_dismiss(?callable $redirector = null)
    {
        $nonce      = is_string($_GET['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $authorized = current_user_can(Management_Access::CAPABILITY)
            && '' !== $nonce
            && wp_verify_nonce($nonce, self::NONCE_ACTION);

        if (! $authorized) {
            if (null !== $redirector) {
                return new \WP_Error('wpmcp_forbidden', __('You are not allowed to dismiss this notice.', 'wpmcp'));
            }
            wp_die(esc_html__('You are not allowed to dismiss this notice.', 'wpmcp'), 403);
        }

        $this->dismiss(get_current_user_id());

        // Sanitized twice on purpose, as Pro_Banner does: wp_sanitize_redirect()
        // while still rawurlencoded, esc_url_raw() on the decoded URL.
        $redirect = is_string($_GET['redirect_to'] ?? null) ? esc_url_raw(rawurldecode(wp_sanitize_redirect(wp_unslash($_GET['redirect_to'])))) : '';
        $target   = '' !== $redirect ? $redirect : admin_url('admin.php?page=wpmcp');

        if (null !== $redirector) {
            $redirector($target);
            return null;
        }

        wp_safe_redirect($target, 302, 'wpmcp');
        exit;
    }

    /** The current wp-admin URL, for the post-dismiss redirect. */
    private static function current_url(): string
    {
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        return '' !== $uri ? $uri : admin_url('admin.php?page=wpmcp');
    }
}
