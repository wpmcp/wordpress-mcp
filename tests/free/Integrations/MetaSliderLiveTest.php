<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #705 against the real MetaSlider (bin/test-local.sh --live-galleries,
 * WPMCP_LIVE_GALLERIES=1): the ops create a slider with real uploaded
 * images, reorder and caption its slides, and MetaSlider's own [metaslider]
 * shortcode renders the result; each change is undone and the render
 * follows. Skipped elsewhere; the live leg runs with --fail-on-skipped so it
 * cannot pass vacuously.
 *
 * @group galleries-live
 */
final class MetaSliderLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('METASLIDER_VERSION') || ! class_exists('MetaSliderPlugin')) {
            $this->markTestSkipped('Needs the real MetaSlider (bin/test-local.sh --live-galleries, WPMCP_LIVE_GALLERIES=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_metaslider_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_metaslider_write');
        parent::tearDown();
    }

    private function call(string $op, array $args, bool $write = true): array
    {
        $pair = new Plugin_Data_Integration();
        $out  = $write ? $pair->handle_write([ 'operation' => $op, 'args' => $args ]) : $pair->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function upload(string $name): int
    {
        $file = get_temp_dir() . $name . '.jpg';
        copy(DIR_TESTDATA . '/images/canola.jpg', $file);
        $id = (int) self::factory()->attachment->create_upload_object($file);
        @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- test scratch copy.
        return $id;
    }

    /** The image file names in the order MetaSlider renders them. */
    private function rendered(int $slider): array
    {
        $html = do_shortcode('[metaslider id="' . $slider . '"]');
        preg_match_all('/(ms-first|ms-second)[^"\'\s]*\.jpg/', $html, $m);
        return array_values(array_unique(array_map(static fn (string $f): string => str_starts_with($f, 'ms-first') ? 'first' : 'second', $m[1])));
    }

    public function test_create_reorder_and_caption_render_through_metaslider_and_roll_back(): void
    {
        $first  = $this->upload('ms-first');
        $second = $this->upload('ms-second');

        $made   = $this->call('metaslider-create-slider', [ 'title' => 'Live slider', 'image_ids' => [ $first, $second ] ]);
        $slider = (int) $made['result']['slider']['id'];
        $slides = array_column($made['result']['slider']['slides'], 'id');
        $this->assertCount(2, $slides);
        $this->assertSame([ 'first', 'second' ], $this->rendered($slider), 'MetaSlider renders both slides in order');

        $read = $this->call('metaslider-get-slider', [ 'id' => $slider ], false)['result']['slider'];
        $this->assertSame([ $first, $second ], array_column($read['slides'], 'image_id'));

        $moved = $this->call('metaslider-reorder-slides', [ 'id' => $slider, 'slide_ids' => array_reverse($slides) ]);
        $this->assertSame([ 'second', 'first' ], $this->rendered($slider), 'MetaSlider renders the new order');

        $caption = $this->call('metaslider-update-slide', [ 'slide_id' => $slides[0], 'caption' => 'Live caption text', 'url' => 'https://example.test/live' ]);
        $html    = do_shortcode('[metaslider id="' . $slider . '"]');
        $this->assertStringContainsString('Live caption text', $html);
        $this->assertStringContainsString('https://example.test/live', $html);

        $this->assertTrue(Rollback_Service::restore_operation($caption['operation_id']));
        $this->assertStringNotContainsString('Live caption text', do_shortcode('[metaslider id="' . $slider . '"]'));
        $this->assertGreaterThan(0, Rollback_Service::restore_session($moved['session_id']));
        $this->assertSame([ 'first', 'second' ], $this->rendered($slider));

        $added = $this->call('metaslider-add-slides', [ 'id' => $slider, 'image_ids' => [ $this->upload('ms-second') ] ]);
        $this->assertCount(3, $this->call('metaslider-get-slider', [ 'id' => $slider ], false)['result']['slider']['slides']);
        $this->assertGreaterThan(0, Rollback_Service::restore_session($added['session_id']));
        $this->assertCount(2, $this->call('metaslider-get-slider', [ 'id' => $slider ], false)['result']['slider']['slides']);

        $this->assertGreaterThan(0, Rollback_Service::restore_session($made['session_id']));
        $this->assertSame('trash', get_post_status($slider));
        $this->assertStringContainsString('not found', do_shortcode('[metaslider id="' . $slider . '"]'));
    }
}
