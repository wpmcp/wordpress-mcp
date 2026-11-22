<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What ElementsKit Lite ships, read from its own config lists (issue #1012).
 *
 * ElementsKit Lite 4.2.0 (wordpress.org build) has no version constant; its
 * main file defines `final class ElementsKit_Lite`, which is the presence
 * check (filterable as wpmcp_elementskit_active for sites and tests).
 *
 * The widget catalog is ElementsKit_Lite\Config\Widget_List::get_list('full'),
 * the list core/build-widgets.php registers from. Widgets switched off are
 * unregistered by Elementor's Element Manager, so Elementor's widgets manager
 * alone would not list them; the config list does. Modules come from
 * Module_List: get_list('required') rows are always loaded, get_list('optional')
 * rows are the switches. A row whose package is 'pro-disabled' is a Pro
 * placeholder: never loaded by Lite and never saved by its settings page.
 */
final class ElementsKit_Catalog
{
    /** The plugin's main file, for the not-active refusal. */
    public const PLUGIN_FILE = 'elementskit-lite/elementskit-lite.php';

    /** The product name the refusal shows. */
    public const PRODUCT = 'ElementsKit Elementor Addons';

    private const WIDGET_LIST = '\\ElementsKit_Lite\\Config\\Widget_List';
    private const MODULE_LIST = '\\ElementsKit_Lite\\Config\\Module_List';

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_elementskit_active', class_exists('ElementsKit_Lite', false));
    }

    /** Whether ElementsKit's config lists can be read on this request. */
    public function loaded(): bool
    {
        return class_exists(self::WIDGET_LIST) && class_exists(self::MODULE_LIST);
    }

    /** @return array<string,array<string,mixed>> widget id => config row, in ElementsKit's order */
    public function widgets(): array
    {
        return $this->rows(self::WIDGET_LIST, 'full');
    }

    /** @return array<string,array<string,mixed>> */
    public function optional_modules(): array
    {
        return $this->rows(self::MODULE_LIST, 'optional');
    }

    /** @return array<string,array<string,mixed>> */
    public function required_modules(): array
    {
        return $this->rows(self::MODULE_LIST, 'required');
    }

    /** Whether a row is usable in Lite (not a Pro placeholder). */
    public function available(array $row): bool
    {
        return 'pro-disabled' !== ($row['package'] ?? '');
    }

    /**
     * The widget names Elementor has registered on this request, as keys.
     * Empty while Elementor is not loaded.
     *
     * @return array<string,true>
     */
    public function registered_widget_names(): array
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return [];
        }
        $manager = \Elementor\Plugin::instance()->widgets_manager ?? null;
        if (! is_object($manager) || ! method_exists($manager, 'get_widget_types')) {
            return [];
        }
        $names = [];
        foreach (array_keys((array) $manager->get_widget_types()) as $name) {
            $names[ (string) $name ] = true;
        }
        return $names;
    }

    /** @return array<string,array<string,mixed>> */
    private function rows(string $class, string $which): array
    {
        if (! class_exists($class)) {
            return [];
        }
        $rows = [];
        foreach ((array) $class::instance()->get_list($which) as $id => $row) {
            if (is_array($row)) {
                $rows[ (string) $id ] = $row;
            }
        }
        return $rows;
    }
}
