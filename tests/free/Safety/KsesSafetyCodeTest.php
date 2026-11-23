<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Code_Fields;
use WPMCP\Safety\Kses_Safety;
use WPMCP\Safety\Post_Write_Refused;
use WPMCP\Tests\Free\Safety\Kses\Without_Unfiltered_Html;

/**
 * Kses_Safety::values_refusal() with a builder's Code_Fields: code the
 * builder runs or prints as is (a script with no tags, PHP) passes kses
 * unchanged, so a user without unfiltered_html may only keep the code the
 * data already holds where code goes. Attributes the builder assembles from
 * a stored name and value are judged by kses as the markup they become.
 */
final class KsesSafetyCodeTest extends \WP_UnitTestCase
{
    use Without_Unfiltered_Html;

    private Kses_Safety $kses;

    private Code_Fields $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kses   = new Kses_Safety();
        $this->fields = new class () implements Code_Fields {
            /** Arrays with a 'script' key hold code; arrays with 'attr' => [name, value] print an attribute. */
            public function code(mixed $value): array
            {
                $out = [];
                foreach ($this->arrays($value) as $node) {
                    if (is_string($node['script'] ?? null) && '' !== $node['script']) {
                        $out[] = "script\0" . $node['script'];
                    }
                }

                return $out;
            }

            public function markup(mixed $value): array
            {
                $out = [];
                foreach ($this->arrays($value) as $node) {
                    $attr = $node['attr'] ?? null;
                    if (is_array($attr) && is_string($attr[0] ?? null) && is_string($attr[1] ?? null)) {
                        $out[] = '<a ' . $attr[0] . '="' . esc_attr($attr[1]) . '"></a>';
                    }
                }

                return $out;
            }

            /** @return list<array<mixed>> */
            private function arrays(mixed $value): array
            {
                if (! is_array($value)) {
                    return [];
                }
                $out = [$value];
                foreach ($value as $item) {
                    array_push($out, ...$this->arrays($item));
                }

                return $out;
            }
        };
    }

    public function test_new_code_is_refused_with_its_own_wording(): void
    {
        $this->log_in_without_unfiltered_html();

        $out = $this->kses->values_refusal([], ['block' => ['script' => 'alert(document.cookie)']], 'Bricks page', $this->fields);

        $this->assertWPError($out);
        $this->assertSame(Kses_Safety::CODE, $out->get_error_code());
        $this->assertSame(['status' => 403], $out->get_error_data());
        $this->assertSame(
            'This Bricks page holds new code, such as a script, PHP or a code element, that only users with the unfiltered_html capability (on a network, super admins) may add or change, so nothing was written. Ask a user with unfiltered_html to make this change.',
            $out->get_error_message()
        );
        $this->assertSame('alert(document.cookie)', wp_kses_post('alert(document.cookie)'), 'kses must keep the script, or the test proves nothing.');
    }

    public function test_code_the_data_already_holds_where_code_goes_is_kept(): void
    {
        $this->log_in_without_unfiltered_html();
        $before = ['a' => ['script' => 'track();'], 'b' => ['text' => 'Old']];

        // Moved, copied beside itself, or left in place while other values change.
        $this->assertNull($this->kses->values_refusal($before, ['b' => ['text' => 'New'], 'c' => ['script' => 'track();']], 'page', $this->fields));
        $this->assertNull($this->kses->values_refusal($before, [['script' => 'track();'], ['script' => 'track();']], 'page', $this->fields));
        // Code removed.
        $this->assertNull($this->kses->values_refusal($before, ['b' => ['text' => 'Old']], 'page', $this->fields));
    }

    public function test_changed_code_is_refused(): void
    {
        $this->log_in_without_unfiltered_html();

        $out = $this->kses->values_refusal(['a' => ['script' => 'track();']], ['a' => ['script' => 'track(); steal();']], 'page', $this->fields);

        $this->assertWPError($out);
        $this->assertSame(Kses_Safety::CODE, $out->get_error_code());
    }

    /** A value stored where it is only text never counts as stored code. */
    public function test_a_value_stored_outside_a_code_field_is_not_stored_code(): void
    {
        $this->log_in_without_unfiltered_html();

        $out = $this->kses->values_refusal(['a' => ['text' => 'steal();']], ['a' => ['script' => 'steal();']], 'page', $this->fields);

        $this->assertWPError($out);
        $this->assertSame(Kses_Safety::CODE, $out->get_error_code());
    }

    public function test_assembled_attributes_are_judged_as_the_markup_they_print(): void
    {
        $this->log_in_without_unfiltered_html();

        foreach ([['onclick', 'steal()'], ['href', 'javascript:steal()'], ['x-on:click', 'steal()']] as $attr) {
            $out = $this->kses->values_refusal([], ['el' => ['attr' => $attr]], 'page', $this->fields);
            $this->assertWPError($out, $attr[0]);
            $this->assertSame(Kses_Safety::CODE, $out->get_error_code());
        }
        foreach ([['data-section', 'hero'], ['aria-label', 'Main & more'], ['href', 'https://example.org/?a=1&b=2'], ['title', 'Hi']] as $attr) {
            $this->assertNull($this->kses->values_refusal([], ['el' => ['attr' => $attr]], 'page', $this->fields), $attr[0]);
        }

        // An attribute the data already prints is kept.
        $stored = ['el' => ['attr' => ['onclick', 'track()']]];
        $this->assertNull($this->kses->values_refusal($stored, ['moved' => ['attr' => ['onclick', 'track()']]], 'page', $this->fields));
    }

    public function test_without_code_fields_only_markup_is_judged(): void
    {
        $this->log_in_without_unfiltered_html();

        $this->assertNull($this->kses->values_refusal([], ['block' => ['script' => 'alert(1)']], 'page'));
    }

    public function test_a_user_with_unfiltered_html_writes_any_code(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $this->assertTrue(current_user_can('unfiltered_html'));

        $this->assertNull($this->kses->values_refusal([], ['block' => ['script' => 'alert(1)'], 'el' => ['attr' => ['onclick', 'go()']]], 'page', $this->fields));
    }

    public function test_assert_values_throws_the_code_refusal(): void
    {
        $this->log_in_without_unfiltered_html();

        try {
            $this->kses->assert_values([], ['block' => ['script' => 'alert(1)']], 'page', $this->fields);
            $this->fail('New code must be refused.');
        } catch (Post_Write_Refused $e) {
            $this->assertSame(Kses_Safety::CODE, $e->error()->get_error_code());
        }
        $this->kses->assert_values(['block' => ['script' => 'alert(1)']], ['block' => ['script' => 'alert(1)']], 'page', $this->fields);
    }
}
