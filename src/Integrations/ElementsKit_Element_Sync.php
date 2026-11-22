<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor's Element Manager list (elementor_disabled_elements) kept in
 * step with ElementsKit widget switches (issue #1012), the way ElementsKit
 * Lite 4.2.0's compatibility/element-manager/init.php sync_with_elementskit()
 * does on a settings save:
 *
 *  - a widget's Elementor names are elementskit-<slug> and ekit-<slug>,
 *    where <slug> is the widget id resolved by correct_widget_name($id, true)
 *    (advanced-accordion is advance-accordion, advanced-tab is tab, social
 *    is social-media, tab is simple-tab);
 *  - switching a widget off appends each of the two names Elementor has
 *    registered and the list does not hold yet;
 *  - switching it on removes both names wherever they are;
 *  - names that are not ElementsKit's are never touched.
 *
 * ElementsKit's own check for "not held yet" tests the list's keys, not its
 * values, so it could append a name twice; Elementor unregisters a disabled
 * widget, so that does not happen in practice, and this sync tests values.
 */
final class ElementsKit_Element_Sync
{
    private const PREFIXES = [ 'elementskit-', 'ekit-' ];

    /** correct_widget_name($slug, true): widget id => the slug its Elementor name carries. */
    private const ALIASES = [
        'advanced-accordion' => 'advance-accordion',
        'advanced-tab'       => 'tab',
        'social'             => 'social-media',
        'tab'                => 'simple-tab',
    ];

    /** @return string[] the two Elementor names a widget id can carry */
    public function names(string $widget): array
    {
        $slug = self::ALIASES[ $widget ] ?? $widget;
        return array_map(static fn (string $prefix): string => $prefix . $slug, self::PREFIXES);
    }

    /**
     * The disabled list after a batch of widget switches.
     *
     * @param array<string,bool> $switches widget id => on
     * @param string[]           $disabled the current list
     * @param array<string,true> $registered Elementor's registered widget names
     * @return string[]
     */
    public function apply(array $switches, array $disabled, array $registered): array
    {
        foreach ($switches as $widget => $on) {
            foreach ($this->names((string) $widget) as $name) {
                if ($on) {
                    if (in_array($name, $disabled, true)) {
                        $disabled = array_values(array_diff($disabled, [ $name ]));
                    }
                } elseif (isset($registered[ $name ]) && ! in_array($name, $disabled, true)) {
                    $disabled[] = $name;
                }
            }
        }
        return $disabled;
    }
}
