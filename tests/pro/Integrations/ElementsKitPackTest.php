<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/elementskit-stubs.php';

/**
 * The ElementsKit pack (issue #1012), the last suite of #653, on the theme
 * dispatcher pair: list-elementskit-widgets, get-elementskit-modules and
 * set-elementskit-modules, paid tier.
 *
 * ElementsKit Lite keeps its switches in the widget_list and module_list
 * rows of elementskit_options, next to user_data and settings, which hold
 * API keys and are never read out. A widget switch also goes through
 * Elementor's Element Manager option, elementor_disabled_elements, exactly
 * as ElementsKit's own compatibility/element-manager sync keeps it, so one
 * switch writes both options under one option_set snapshot.
 */
class ElementsKitPackTest extends \WP_UnitTestCase
{
    private const SECRET = 'ekit-test-secret-9f3a';

    /** @var string[] widget names registered by a test, unregistered in tearDown */
    private array $widgets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->seed_catalog();
        delete_option('elementskit_options');
        delete_option('elementor_disabled_elements');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_elementskit_active');
        if ([] !== $this->widgets && class_exists('\\Elementor\\Plugin')) {
            foreach ($this->widgets as $name) {
                \Elementor\Plugin::instance()->widgets_manager->unregister($name);
            }
        }
        $this->widgets = [];
        delete_option('elementskit_options');
        delete_option('elementor_disabled_elements');
        \ElementsKit_Lite\Config\Widget_List::$required = [];
        \ElementsKit_Lite\Config\Widget_List::$optional = [];
        \ElementsKit_Lite\Config\Module_List::$required = [];
        \ElementsKit_Lite\Config\Module_List::$optional = [];
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** The shape of ElementsKit Lite 4.2.0's config/widget-list.php and module-list.php, trimmed. */
    private function seed_catalog(): void
    {
        \ElementsKit_Lite\Config\Widget_List::$required = [];
        \ElementsKit_Lite\Config\Widget_List::$optional = [
            'button'          => [ 'slug' => 'button', 'title' => 'Button', 'package' => 'free', 'widget-category' => 'general' ],
            'heading'         => [ 'slug' => 'heading', 'title' => 'Heading', 'package' => 'free', 'widget-category' => 'general' ],
            'tab'             => [ 'slug' => 'tab', 'title' => 'Tab', 'package' => 'free', 'widget-category' => 'general', 'hasJS' => true ],
            'nav-menu'        => [ 'slug' => 'nav-menu', 'title' => 'Nav Menu', 'package' => 'free', 'widget-category' => 'header-footer' ],
            'advanced-slider' => [ 'slug' => 'advanced-slider', 'title' => 'Advanced Slider', 'package' => 'pro-disabled', 'widget-category' => 'general' ],
        ];
        \ElementsKit_Lite\Config\Module_List::$required = [
            'dynamic-content' => [ 'slug' => 'dynamic-content', 'package' => 'free' ],
            'layout-manager'  => [ 'slug' => 'layout-manager', 'package' => 'free' ],
        ];
        \ElementsKit_Lite\Config\Module_List::$optional = [
            'header-footer'  => [ 'slug' => 'header-footer', 'title' => 'Header Footer', 'package' => 'free' ],
            'megamenu'       => [ 'slug' => 'megamenu', 'package' => 'free', 'title' => 'Mega Menu' ],
            'widget-builder' => [ 'slug' => 'widget-builder', 'package' => 'free', 'title' => 'Widget Builder' ],
            'parallax'       => [ 'slug' => 'parallax', 'package' => 'pro-disabled', 'title' => 'Parallax Effects' ],
        ];
    }

    /** elementskit_options as ElementsKit's settings page saves it, with API keys in user_data and settings. */
    private function seed_options(): array
    {
        $options = [
            'widget_list' => [
                'button'   => [ 'slug' => 'button', 'title' => 'Button', 'package' => 'free', 'widget-category' => 'general', 'status' => 'active' ],
                'heading'  => [ 'slug' => 'heading', 'title' => 'Heading', 'package' => 'free', 'widget-category' => 'general', 'status' => 'active' ],
                'tab'      => [ 'slug' => 'tab', 'title' => 'Tab', 'package' => 'free', 'widget-category' => 'general', 'hasJS' => true, 'status' => 'active' ],
                'nav-menu' => [ 'slug' => 'nav-menu', 'title' => 'Nav Menu', 'package' => 'free', 'widget-category' => 'header-footer', 'status' => 'inactive' ],
            ],
            'module_list' => [
                'header-footer'  => [ 'slug' => 'header-footer', 'title' => 'Header Footer', 'package' => 'free', 'status' => 'active' ],
                'megamenu'       => [ 'slug' => 'megamenu', 'package' => 'free', 'title' => 'Mega Menu', 'status' => 'active' ],
                'widget-builder' => [ 'slug' => 'widget-builder', 'package' => 'free', 'title' => 'Widget Builder', 'status' => 'inactive' ],
            ],
            'user_data'   => [ 'mail_chimp' => [ 'token' => self::SECRET ], 'facebook' => [ 'page_token' => self::SECRET ] ],
            'settings'    => [ 'google_map_api_key' => self::SECRET ],
        ];
        update_option('elementskit_options', $options);
        return $options;
    }

    private function register_fixture_widgets(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
        require_once __DIR__ . '/../../support/elementor-addon-widgets.php';
        require_once __DIR__ . '/../../support/elementskit-widgets.php';
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        $manager->get_widget_types();
        foreach (
            [
                \Elementor\ElementsKit_Widget_Fixture_Button::class,
                \Elementor\ElementsKit_Widget_Fixture_Simple_Tab::class,
                \Elementor\ElementsKit_Widget_Fixture_Nav_Menu::class,
            ] as $class
        ) {
            $widget = new $class();
            $manager->register($widget);
            $this->widgets[] = $widget->get_name();
        }
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-elementskit-modules', 'args' => $args ]);
    }

    public function test_three_paid_ops_are_registered_and_answer_inactive_without_elementskit(): void
    {
        add_filter('wpmcp_elementskit_active', '__return_false');
        $catalog = [];
        foreach ((new Theme_Integration())->catalog()['operations'] as $row) {
            $catalog[ $row['name'] ] = $row;
        }
        foreach ([ 'list-elementskit-widgets', 'get-elementskit-modules', 'set-elementskit-modules' ] as $op) {
            $this->assertArrayHasKey($op, $catalog, $op);
            $this->assertFalse($catalog[ $op ]['dependency_met'], 'ElementsKit is not active');
        }
        $this->assertSame('addon_suite_inactive', $this->read('list-elementskit-widgets')['error']['code']);
        $this->assertSame('addon_suite_inactive', $this->read('get-elementskit-modules')['error']['code']);

        $before = Snapshot_Store::row_count();
        $this->assertSame('addon_suite_inactive', $this->write([ 'widgets' => [ 'button' => false ] ])['error']['code']);
        $this->assertFalse(get_option('elementskit_options'), 'Nothing was written');
        $this->assertSame($before, Snapshot_Store::row_count(), 'No snapshot was taken');

        Gate::set_pro_for_tests(false);
        $names = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        $this->assertNotContains('set-elementskit-modules', $names, 'Paid tier');
        $this->assertNotContains('list-elementskit-widgets', $names, 'Paid tier');
    }

    public function test_widget_catalog_carries_the_active_state_and_never_the_api_keys(): void
    {
        $this->seed_options();
        update_option('elementor_disabled_elements', [ 'ekit-nav-menu' ]);

        $out = $this->read('list-elementskit-widgets');
        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame(5, $result['count']);
        $rows = array_column($result['widgets'], null, 'id');
        $this->assertSame([ 'advanced-slider', 'button', 'heading', 'nav-menu', 'tab' ], array_keys($rows));
        $this->assertTrue($rows['button']['active']);
        $this->assertFalse($rows['nav-menu']['active']);
        $this->assertTrue($rows['nav-menu']['disabled_in_elementor']);
        $this->assertFalse($rows['button']['disabled_in_elementor']);
        $this->assertFalse($rows['advanced-slider']['available'], 'A pro-disabled widget needs ElementsKit Pro');
        $this->assertFalse($rows['advanced-slider']['active']);
        $this->assertSame('Tab', $rows['tab']['title']);
        $this->assertSame('general', $rows['tab']['category']);

        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('user_data', $json);
        $this->assertStringNotContainsString('google_map_api_key', $json);
    }

    public function test_an_unsaved_widget_list_reads_every_free_widget_as_on(): void
    {
        $out = $this->read('get-elementskit-modules')['result'];
        $this->assertSame([ 'button' => true, 'heading' => true, 'nav-menu' => true, 'tab' => true ], $out['widgets']);
        $this->assertSame([ 'header-footer' => true, 'megamenu' => true, 'widget-builder' => true ], $out['modules']);
    }

    public function test_module_state_comes_from_the_two_lists_only(): void
    {
        $this->seed_options();

        $out = $this->read('get-elementskit-modules');
        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];
        $this->assertSame([ 'button' => true, 'heading' => true, 'nav-menu' => false, 'tab' => true ], $result['widgets']);
        $this->assertSame([ 'header-footer' => true, 'megamenu' => true, 'widget-builder' => false ], $result['modules']);
        $this->assertSame([ 'dynamic-content', 'layout-manager' ], $result['required_modules'], 'Always on');
        $this->assertSame([ 'widgets' => [ 'advanced-slider' ], 'modules' => [ 'parallax' ] ], $result['pro_only']);

        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('user_data', $json);
        $this->assertStringNotContainsString('settings', $json);
    }

    public function test_a_switch_writes_both_options_in_one_option_set_and_rolls_back_exactly(): void
    {
        $this->register_fixture_widgets();
        $options  = $this->seed_options();
        $disabled = [ 'some-other-widget', 'ekit-nav-menu' ];
        update_option('elementor_disabled_elements', $disabled);

        $out = $this->write([
            'widgets' => [ 'button' => false, 'tab' => false, 'nav-menu' => true, 'heading' => true ],
            'modules' => [ 'megamenu' => false, 'header-footer' => true ],
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'widgets' => [ 'button' => false, 'tab' => false, 'nav-menu' => true ], 'modules' => [ 'megamenu' => false ] ], $out['result']['changed']);

        $snapshot = Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot'];
        $this->assertSame('option_set', $snapshot['object_type']);
        $this->assertSame('elementor_disabled_elements,elementskit_options', $snapshot['object_id']);

        $this->assertSame(
            [ 'some-other-widget', 'elementskit-button', 'elementskit-simple-tab' ],
            get_option('elementor_disabled_elements'),
            'Registered prefixed names added (tab resolves to simple-tab), a re-enabled widget removed, foreign names kept'
        );

        $after = get_option('elementskit_options');
        $this->assertSame($options['user_data'], $after['user_data'], 'API keys untouched');
        $this->assertSame($options['settings'], $after['settings']);
        $this->assertSame(serialize($options['user_data']), serialize($after['user_data']), 'Byte for byte');
        $this->assertSame(array_keys($options), array_keys($after), 'Key order kept');
        $this->assertSame('inactive', $after['widget_list']['button']['status']);
        $this->assertSame('inactive', $after['widget_list']['tab']['status']);
        $this->assertSame('active', $after['widget_list']['nav-menu']['status']);
        $this->assertSame($options['widget_list']['heading'], $after['widget_list']['heading']);
        $this->assertSame(array_diff_key($options['widget_list']['tab'], [ 'status' => 1 ]), array_diff_key($after['widget_list']['tab'], [ 'status' => 1 ]), 'Only the status of a row changes');
        $this->assertSame('inactive', $after['module_list']['megamenu']['status']);
        $this->assertSame($options['module_list']['widget-builder'], $after['module_list']['widget-builder']);

        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($out), 'The write answer carries no API key');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(serialize($options), serialize(get_option('elementskit_options')), 'elementskit_options exactly as before');
        $this->assertSame($disabled, get_option('elementor_disabled_elements'), 'elementor_disabled_elements exactly as before');
    }

    public function test_a_module_only_switch_still_snapshots_both_options(): void
    {
        $options = $this->seed_options();
        $out     = $this->write([ 'modules' => [ 'widget-builder' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([ 'widgets' => [], 'modules' => [ 'widget-builder' => true ] ], $out['result']['changed']);
        $this->assertSame('active', get_option('elementskit_options')['module_list']['widget-builder']['status']);
        $this->assertFalse(get_option('elementor_disabled_elements'), 'A module switch leaves Elementor alone');
        $this->assertSame('option_set', Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot']['object_type']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(serialize($options), serialize(get_option('elementskit_options')));
        $this->assertFalse(get_option('elementor_disabled_elements'));
    }

    public function test_a_widget_with_no_saved_row_gets_its_catalog_row_and_an_undo_deletes_what_the_write_created(): void
    {
        $out = $this->write([ 'widgets' => [ 'heading' => false ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(
            [ 'widget_list' => [ 'heading' => [ 'slug' => 'heading', 'title' => 'Heading', 'package' => 'free', 'widget-category' => 'general', 'status' => 'inactive' ] ] ],
            get_option('elementskit_options')
        );
        $this->assertFalse($this->read('get-elementskit-modules')['result']['widgets']['heading']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(get_option('elementskit_options'), 'An option the write created is gone again');
        $this->assertFalse(get_option('elementor_disabled_elements'));
    }

    public function test_refusals_and_no_ops_write_nothing_and_take_no_snapshot(): void
    {
        $options = $this->seed_options();
        $before  = Snapshot_Store::row_count();

        $unknown = $this->write([ 'widgets' => [ 'button' => false, 'no-such-widget' => true ] ]);
        $this->assertSame('unknown_widget', $unknown['error']['code']);
        $this->assertSame([ 'no-such-widget' ], $unknown['error']['data']['widgets']);

        $this->assertSame('unknown_module', $this->write([ 'modules' => [ 'megamenu' => false, 'nope' => true ] ])['error']['code']);
        $this->assertSame('required_module', $this->write([ 'modules' => [ 'dynamic-content' => false ] ])['error']['code'], 'Required modules are always on');
        $this->assertSame('pro_only', $this->write([ 'widgets' => [ 'advanced-slider' => true ] ])['error']['code']);
        $this->assertSame('pro_only', $this->write([ 'modules' => [ 'parallax' => true ] ])['error']['code']);
        $this->assertSame('nothing_to_switch', $this->write([])['error']['code']);
        $this->assertSame('nothing_to_switch', $this->write([ 'widgets' => [], 'modules' => [] ])['error']['code']);

        $noop = $this->write([ 'widgets' => [ 'button' => true ], 'modules' => [ 'widget-builder' => false ] ]);
        $this->assertArrayNotHasKey('error', $noop);
        $this->assertFalse($noop['recoverable']);
        $this->assertSame([ 'widgets' => [], 'modules' => [] ], $noop['result']['changed']);

        $this->assertSame(serialize($options), serialize(get_option('elementskit_options')), 'Nothing was written');
        $this->assertFalse(get_option('elementor_disabled_elements'));
        $this->assertSame($before, Snapshot_Store::row_count(), 'No undo point for a refusal or a no-op');
    }

    public function test_an_unreadable_settings_option_is_refused(): void
    {
        update_option('elementskit_options', 'not-an-array');
        $before = Snapshot_Store::row_count();
        $this->assertSame('elementskit_options_unreadable', $this->write([ 'widgets' => [ 'button' => false ] ])['error']['code']);
        $this->assertSame('not-an-array', get_option('elementskit_options'));
        $this->assertSame($before, Snapshot_Store::row_count());
    }
}
