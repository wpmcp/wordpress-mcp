<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #705 against the real Smart Slider 3 (bin/test-local.sh
 * --live-galleries, WPMCP_LIVE_GALLERIES=1): Smart Slider's own installer
 * creates its tables, its own ModelSliders and ModelSlides create the
 * slider and its image slides, the ops reorder and change them, and
 * Smart Slider's own model reads the result back. Its "slider changed"
 * flag, which makes its renderer drop the cached slider, is checked after
 * each write and each undo. Skipped elsewhere; the live leg runs with
 * --fail-on-skipped.
 *
 * @group galleries-live
 */
final class SmartSliderLiveTest extends \WP_UnitTestCase
{
    private object $app;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('NEXTEND_SMARTSLIDER_3') || ! class_exists('\\Nextend\\SmartSlider3\\Application\\ApplicationSmartSlider3')) {
            $this->markTestSkipped('Needs the real Smart Slider 3 (bin/test-local.sh --live-galleries, WPMCP_LIVE_GALLERIES=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_enable_smartslider_write', '__return_true');
        $this->app = \Nextend\SmartSlider3\Application\ApplicationSmartSlider3::getInstance()->getApplicationTypeFrontend();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_smartslider_write');
        parent::tearDown();
    }

    private function call(string $op, array $args, bool $write = true): array
    {
        $pair = new Plugin_Data_Integration();
        $out  = $write ? $pair->handle_write([ 'operation' => $op, 'args' => $args ]) : $pair->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** @return string[] Slide titles in the order Smart Slider's own model reads them. */
    private function titles(int $slider): array
    {
        return array_column((new \Nextend\SmartSlider3\Application\Model\ModelSlides($this->app))->getAll($slider), 'title');
    }

    private function changed(int $slider): int
    {
        return (new \Nextend\SmartSlider3\Application\Helper\HelperSliderChanged(new \Nextend\SmartSlider3\Application\Model\ModelSliders($this->app)))->isSliderChanged($slider);
    }

    private function settle(int $slider): void
    {
        (new \Nextend\SmartSlider3\Application\Helper\HelperSliderChanged(new \Nextend\SmartSlider3\Application\Model\ModelSliders($this->app)))->setSliderChanged($slider, 0);
    }

    public function test_reorder_and_update_reach_smart_sliders_model_and_cache_and_roll_back(): void
    {
        $id     = (int) (new \Nextend\SmartSlider3\Application\Model\ModelSliders($this->app))->create([ 'title' => 'Live slider', 'type' => 'simple' ]);
        $model  = new \Nextend\SmartSlider3\Application\Model\ModelSlides($this->app);
        $slides = [];
        foreach ([ 'First', 'Second', 'Third' ] as $title) {
            $slides[] = (int) $model->createQuickImage([ 'media' => '$upload$/2026/01/' . strtolower($title) . '.jpg', 'title' => $title, 'description' => '' ], $id);
        }
        $this->assertGreaterThan(0, $id);

        $read = $this->call('smartslider-get-slider', [ 'id' => $id ], false)['result']['slider'];
        $this->assertSame($slides, array_column($read['slides'], 'id'));
        $this->assertSame(wp_upload_dir()['baseurl'] . '/2026/01/first.jpg', $read['slides'][0]['image']);

        $this->settle($id);
        $moved = $this->call('smartslider-reorder-slides', [ 'id' => $id, 'slide_ids' => array_reverse($slides) ]);
        $this->assertSame([ 'Third', 'Second', 'First' ], $this->titles($id), 'Smart Slider reads the new order');
        $this->assertSame(1, $this->changed($id), 'Smart Slider will rebuild the cached slider');

        $this->settle($id);
        $this->assertGreaterThan(0, Rollback_Service::restore_session($moved['session_id']));
        $this->assertSame([ 'First', 'Second', 'Third' ], $this->titles($id));
        $this->assertSame(1, $this->changed($id), 'the undo also marks the slider changed');

        $this->settle($id);
        $edit = $this->call('smartslider-update-slide', [ 'slide_id' => $slides[1], 'title' => 'Middle', 'published' => false ]);
        $row  = $model->get($slides[1]);
        $this->assertSame('Middle', $row['title']);
        $this->assertSame(0, (int) $row['published']);
        $this->assertSame(1, $this->changed($id));
        $this->assertTrue(Rollback_Service::restore_operation($edit['operation_id']));
        $row = $model->get($slides[1]);
        $this->assertSame('Second', $row['title']);
        $this->assertSame(1, (int) $row['published']);
    }
}
