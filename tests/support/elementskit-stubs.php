<?php
/**
 * Faithful test doubles for the parts of ElementsKit Lite 4.2.0 (the
 * wordpress.org build, elementskit-lite) the ElementsKit pack reads:
 *
 *  - the main file defines `final class ElementsKit_Lite` and no version
 *    constant, so the class is the presence check;
 *  - ElementsKit_Lite\Config\Widget_List and Module_List extend
 *    Core\Config_List, a singleton whose get_list('full') is the required
 *    list merged with the optional one (pro-disabled rows included, as
 *    core/build-widgets.php and the settings views use it), and
 *    get_list('optional') / get_list('required') the two halves. The
 *    widget list has no required rows. The doubles keep each half in a
 *    static array a test seeds.
 *
 * Loaded only by the tests that need it; a real class always wins.
 */

namespace {

    if (! class_exists('ElementsKit_Lite', false)) {
        final class ElementsKit_Lite
        {
            public static function version()
            {
                return '4.2.0';
            }
        }
    }
}

namespace ElementsKit_Lite\Config {

    if (! class_exists(__NAMESPACE__ . '\\Widget_List', false)) {
        class Widget_List
        {
            /** @var array<string,array<string,mixed>> */
            public static array $required = [];

            /** @var array<string,array<string,mixed>> */
            public static array $optional = [];

            private static ?self $instance = null;

            public static function instance()
            {
                return self::$instance ??= new self();
            }

            public function get_list($data = 'full', $module = null)
            {
                $lists = [
                    'full'     => array_merge(self::$required, self::$optional),
                    'all'      => array_merge(self::$required, self::$optional),
                    'optional' => self::$optional,
                    'required' => self::$required,
                ];
                if (null !== $module) {
                    return $lists[ $data ][ $module ] ?? false;
                }
                return $lists[ $data ] ?? [];
            }
        }
    }

    if (! class_exists(__NAMESPACE__ . '\\Module_List', false)) {
        class Module_List
        {
            /** @var array<string,array<string,mixed>> */
            public static array $required = [];

            /** @var array<string,array<string,mixed>> */
            public static array $optional = [];

            private static ?self $instance = null;

            public static function instance()
            {
                return self::$instance ??= new self();
            }

            public function get_list($data = 'full', $module = null)
            {
                $lists = [
                    'full'     => array_merge(self::$required, self::$optional),
                    'all'      => array_merge(self::$required, self::$optional),
                    'optional' => self::$optional,
                    'required' => self::$required,
                ];
                if (null !== $module) {
                    return $lists[ $data ][ $module ] ?? false;
                }
                return $lists[ $data ] ?? [];
            }
        }
    }
}
