<?php
/**
 * Widget doubles for the ElementsKit pack, registered into Elementor's real
 * widgets manager under the names ElementsKit Lite 4.2.0 registers: most
 * widgets as elementskit-<slug>, the nav menu as ekit-nav-menu, and the
 * 'tab' row of the widget list as elementskit-simple-tab (the alias
 * compatibility/element-manager/init.php resolves with correct_widget_name()).
 * Requires Elementor and tests/support/elementor-addon-widgets.php.
 */

namespace Elementor {

    class ElementsKit_Widget_Fixture_Button extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'elementskit-button';
        }

        public function get_categories()
        {
            return [ 'elementskit' ];
        }
    }

    class ElementsKit_Widget_Fixture_Simple_Tab extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'elementskit-simple-tab';
        }

        public function get_categories()
        {
            return [ 'elementskit' ];
        }
    }

    class ElementsKit_Widget_Fixture_Nav_Menu extends \WPMCP\Tests\Support\ElementorAddons\Fixture_Widget
    {
        public function get_name()
        {
            return 'ekit-nav-menu';
        }

        public function get_categories()
        {
            return [ 'elementskit_headerfooter' ];
        }
    }
}
