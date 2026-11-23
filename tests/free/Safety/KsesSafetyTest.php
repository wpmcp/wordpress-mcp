<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Kses_Safety;
use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Tests\Free\Safety\Kses\Kses_Markup;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;

/**
 * Kses_Safety (issue #1243): the shared check every post_content writer runs
 * before it stores markup for a user without unfiltered_html. It sanitizes
 * the planned value the way the save will and refuses when the result
 * differs, so kses never rewrites a page while the write reports success.
 */
class KsesSafetyTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private Kses_Safety $kses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kses = new Kses_Safety();
    }

    public function test_markup_kses_keeps_is_not_refused(): void
    {
        $this->log_in_without_unfiltered_html();
        $id = self::factory()->post->create();

        $this->assertNull($this->kses->content_refusal($id, '<p>Plain <strong>text</strong></p>', 'page'));
        $this->assertNull($this->kses->content_refusal(0, "<!-- wp:paragraph -->\n<p>Block</p>\n<!-- /wp:paragraph -->", 'page'));
    }

    /**
     * kses re-encodes some markup in ways that parse the same; core's own
     * separator and image blocks save "/>", which kses spaces out. Those are
     * not refused, or nearly every block page would be.
     */
    public function test_rewrites_that_parse_to_the_same_html_are_not_refused(): void
    {
        $this->log_in_without_unfiltered_html();

        foreach ([
            'Tom & Jerry',
            '<a href="https://example.org/?a=1&b=2">x</a>',
            "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
            '<p>5 < 6</p>',
            '<a href=x>y</a>',
            // Block attributes kses re-serializes without changing them.
            '<!-- wp:heading {"level": 2, "className": "intro"} --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->',
        ] as $markup) {
            $this->assertNotSame($markup, wp_unslash(sanitize_post_field('post_content', wp_slash($markup), 0, 'db')), 'kses must rewrite the fixture, or the test proves nothing.');
            $this->assertNull($this->kses->content_refusal(0, $markup, 'page'), $markup);
        }
    }

    public function test_rewrites_that_change_what_the_markup_means_are_refused(): void
    {
        $this->log_in_without_unfiltered_html();

        foreach ([
            Kses_Markup::UNSAFE,
            '<iframe src="https://example.org/embed"></iframe>',
            '<button onclick="go()">Go</button>',
            '<a href="javascript:alert(1)">x</a>',
            '<img src="x.png" alt="a > b">',
            // A block attribute kses re-encodes (A & B becomes A \u0026amp; B).
            '<!-- wp:paragraph {"content":"A & B"} --><p>A &amp; B</p><!-- /wp:paragraph -->',
        ] as $markup) {
            $this->assertWPError($this->kses->content_refusal(0, $markup, 'page'), $markup);
        }
    }

    public function test_markup_kses_would_rewrite_is_refused_with_the_shared_wording(): void
    {
        $this->log_in_without_unfiltered_html();
        $id = self::factory()->post->create();

        $out = $this->kses->content_refusal($id, Kses_Markup::UNSAFE, 'popup');

        $this->assertWPError($out);
        $this->assertSame('unfiltered_html_required', $out->get_error_code());
        $this->assertSame(['status' => 403], $out->get_error_data());
        $this->assertSame(
            'This popup holds markup WordPress saves only for users with the unfiltered_html capability (on a network, super admins), such as scripts or attributes kses removes. Saving it as you would rewrite the live page, so nothing was written. Ask a user with unfiltered_html to make this change.',
            $out->get_error_message()
        );
    }

    public function test_a_user_with_unfiltered_html_is_never_refused(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $this->assertTrue(current_user_can('unfiltered_html'));

        $this->assertNull($this->kses->content_refusal(0, Kses_Markup::UNSAFE, 'page'));
        $this->assertNull($this->kses->values_refusal([], ['html' => Kses_Markup::UNSAFE], 'page'));
    }

    public function test_the_check_follows_the_save_filters_in_place(): void
    {
        $this->log_in_without_unfiltered_html();

        // A writer that lifts kses for its own JSON column stores it as is.
        kses_remove_filters();
        $this->assertNull($this->kses->content_refusal(0, Kses_Markup::UNSAFE, 'form'));
    }

    public function test_assert_content_throws_the_refusal_for_the_registrar(): void
    {
        $this->log_in_without_unfiltered_html();

        try {
            $this->kses->assert_content(0, Kses_Markup::UNSAFE, 'page');
            $this->fail('A write kses would rewrite must be refused.');
        } catch (Post_Write_Refused $e) {
            $this->assertSame('unfiltered_html_required', $e->error()->get_error_code());
        }
        $this->kses->assert_content(0, '<p>Fine</p>', 'page');
    }

    public function test_values_refuses_only_new_strings_kses_would_change(): void
    {
        $this->log_in_without_unfiltered_html();
        $before = ['elements' => [['settings' => ['html' => Kses_Markup::STORED, 'title' => 'Old']]]];

        // Strings already stored are carried over, not judged again.
        $this->assertNull($this->kses->values_refusal($before, ['elements' => [['settings' => ['html' => Kses_Markup::STORED, 'title' => '<b>New</b>']]]], 'page'));

        $out = $this->kses->values_refusal($before, ['elements' => [['settings' => ['html' => Kses_Markup::STORED, 'title' => Kses_Markup::UNSAFE]]]], 'page');
        $this->assertWPError($out);
        $this->assertSame('unfiltered_html_required', $out->get_error_code());

        $object = json_decode((string) wp_json_encode(['a' => ['b' => Kses_Markup::UNSAFE]]));
        $this->assertWPError($this->kses->values_refusal(null, $object, 'page'));
    }

    /** Plain content is sanitized as WordPress does it, and the write is told what went (#440, #1243). */
    public function test_sanitization_reports_what_kses_removes(): void
    {
        $this->log_in_without_unfiltered_html();

        $this->assertSame([], $this->kses->sanitization(0, '<p>Tom & Jerry</p><hr/>'));
        $this->assertSame(
            ['sanitized' => true, 'removed' => ['<script> element', '<iframe> element', 'onclick attribute on <button>']],
            $this->kses->sanitization(0, '<p>a</p><script>x()</script><iframe src="https://example.org/"></iframe><button onclick="go()">Go</button>')
        );
        $this->assertSame(
            ['sanitized' => true, 'removed' => ['changed attribute values or text (such as a disallowed URL protocol)']],
            $this->kses->sanitization(0, '<a href="javascript:alert(1)">x</a>')
        );
    }
}
