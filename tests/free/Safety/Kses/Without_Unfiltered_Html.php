<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety\Kses;

/**
 * A user who may edit everything on the site but has no unfiltered_html
 * (issue #1243): on a network, a site administrator who is not a super
 * admin; on a single site, an administrator the capability was removed from
 * (DISALLOW_UNFILTERED_HTML, or a role editor). Core then runs kses over
 * post_content on save. Hooks are restored by WP_UnitTestCase after each
 * test. The markup fixtures are in Kses_Markup.
 */
trait Without_Unfiltered_Html
{
    protected function log_in_without_unfiltered_html(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        if (! is_multisite()) {
            add_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10, 2);
        }
        // kses_init runs on set_current_user and adds the save filters.
        wp_set_current_user($id);
        $this->assertFalse(current_user_can('unfiltered_html'));
        $this->assertNotFalse(has_filter('content_save_pre', 'wp_filter_post_kses'));

        return $id;
    }

    /**
     * @param string[] $caps
     * @return string[]
     */
    public static function deny_unfiltered_html(array $caps, string $cap): array
    {
        return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps;
    }

    /** Store post_content as is, the way a save by a user with unfiltered_html stores it. */
    protected function store_raw(int $post_id, string $content): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post_id]);
        clean_post_cache($post_id);
        $this->assertSame($content, get_post($post_id)->post_content);
    }

    /** Assert a write was refused with the kses refusal. */
    protected function assert_kses_refused(mixed $out): void
    {
        if (is_array($out) && isset($out['error'])) {
            $code = (string) ($out['error']['code'] ?? $out['code'] ?? '');
            $this->assertSame('unfiltered_html_required', $code, (string) wp_json_encode($out));
            return;
        }
        $this->assertWPError($out, (string) wp_json_encode($out));
        $this->assertSame('unfiltered_html_required', $out->get_error_code());
    }
}
