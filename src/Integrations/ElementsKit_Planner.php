<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns a requested batch of ElementsKit widget and module switches into an
 * ElementsKit_Plan (issue #1012). The whole batch is checked before anything
 * is planned: an unknown id, a required module or a Pro-only row refuses it
 * all. Only rows whose state really changes are rewritten, and only their
 * status; a widget with no saved row gets its catalog row plus a status, as
 * ElementsKit's settings save stores it.
 */
final class ElementsKit_Planner
{
    /** Upper bound on one batch, widgets and modules together. */
    public const MAX_SWITCHES = 200;

    public function __construct(
        private readonly ElementsKit_Catalog $catalog,
        private readonly ElementsKit_Store $store,
        private readonly ElementsKit_Element_Sync $sync
    ) {
    }

    /**
     * @param array<array-key,mixed> $widgets widget id => on
     * @param array<array-key,mixed> $modules module id => on
     */
    public function plan(array $widgets, array $modules): ElementsKit_Plan
    {
        $widgets = $this->switches($widgets);
        $modules = $this->switches($modules);

        if ([] === $widgets && [] === $modules) {
            return ElementsKit_Plan::refused('nothing_to_switch', 'Name at least one widget or module to switch: {"widgets": {"<id>": true|false}, "modules": {"<id>": true|false}}.');
        }
        if (count($widgets) + count($modules) > self::MAX_SWITCHES) {
            return ElementsKit_Plan::refused('too_many_switches', sprintf('One batch switches at most %d widgets and modules together.', self::MAX_SWITCHES), [ 'max' => self::MAX_SWITCHES ]);
        }
        if (! $this->store->readable()) {
            return ElementsKit_Plan::refused('elementskit_options_unreadable', 'The elementskit_options option is not in ElementsKit\'s format (an array), so it is left alone. Save the ElementsKit settings page once, then try again.');
        }
        if (! $this->catalog->loaded()) {
            return ElementsKit_Plan::refused('elementskit_catalog_unavailable', 'ElementsKit\'s widget and module lists are not loaded on this request.');
        }

        $catalog  = $this->catalog->widgets();
        $optional = $this->catalog->optional_modules();
        $required = $this->catalog->required_modules();

        $refusal = $this->refusal($widgets, $modules, $catalog, $optional, $required);
        if (null !== $refusal) {
            return $refusal;
        }

        $lists = $this->store->lists();

        $widget_list     = $lists->widget_list;
        $changed_widgets = [];
        $ordered         = [];
        foreach ($catalog as $id => $row) {
            if (! array_key_exists($id, $widgets)) {
                continue;
            }
            $on             = $widgets[ $id ];
            $ordered[ $id ] = $on;
            if ($lists->widget_on($id) === $on) {
                continue;
            }
            $changed_widgets[ $id ] = $on;
            $widget_list[ $id ]     = $this->with_status($widget_list[ $id ] ?? $row, $on);
        }

        $module_list     = $lists->module_list;
        $changed_modules = [];
        foreach ($optional as $id => $row) {
            if (! array_key_exists($id, $modules) || $lists->module_on($id) === $modules[ $id ]) {
                continue;
            }
            $changed_modules[ $id ] = $modules[ $id ];
            $module_list[ $id ]     = $this->with_status($module_list[ $id ] ?? $row, $modules[ $id ]);
        }

        $current  = $this->store->disabled_elements();
        $disabled = [] === $ordered ? $current : $this->sync->apply($ordered, $current, $this->catalog->registered_widget_names());

        return new ElementsKit_Plan(
            null,
            $changed_widgets,
            $changed_modules,
            [] === $changed_widgets ? null : $widget_list,
            [] === $changed_modules ? null : $module_list,
            $disabled === $current ? null : $disabled,
            array_values(array_diff($disabled, $current)),
            array_values(array_diff($current, $disabled))
        );
    }

    /**
     * @param array<string,bool>                $widgets
     * @param array<string,bool>                $modules
     * @param array<string,array<string,mixed>> $catalog
     * @param array<string,array<string,mixed>> $optional
     * @param array<string,array<string,mixed>> $required
     */
    private function refusal(array $widgets, array $modules, array $catalog, array $optional, array $required): ?ElementsKit_Plan
    {
        $unknown = array_values(array_diff(array_keys($widgets), array_keys($catalog)));
        if ([] !== $unknown) {
            return ElementsKit_Plan::refused('unknown_widget', sprintf('ElementsKit has no widget "%s". list-elementskit-widgets gives every widget id.', implode('", "', $unknown)), [ 'widget' => $unknown[0], 'widgets' => $unknown ]);
        }
        $locked = array_values(array_intersect(array_keys($modules), array_keys($required)));
        if ([] !== $locked) {
            return ElementsKit_Plan::refused('required_module', sprintf('ElementsKit always loads "%s"; a required module cannot be switched.', implode('", "', $locked)), [ 'modules' => $locked ]);
        }
        $unknown = array_values(array_diff(array_keys($modules), array_keys($optional)));
        if ([] !== $unknown) {
            return ElementsKit_Plan::refused('unknown_module', sprintf('ElementsKit has no optional module "%s". get-elementskit-modules gives every module id.', implode('", "', $unknown)), [ 'module' => $unknown[0], 'modules' => $unknown ]);
        }
        $pro = [];
        foreach (array_keys($widgets) as $id) {
            if (! $this->catalog->available($catalog[ $id ])) {
                $pro[] = $id;
            }
        }
        foreach (array_keys($modules) as $id) {
            if (! $this->catalog->available($optional[ $id ])) {
                $pro[] = $id;
            }
        }
        if ([] !== $pro) {
            return ElementsKit_Plan::refused('pro_only', sprintf('"%s" ships with ElementsKit Pro only; ElementsKit Lite never loads or saves it.', implode('", "', $pro)), [ 'ids' => $pro ]);
        }
        return null;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @return array<string,bool>
     */
    private function switches(array $raw): array
    {
        $out = [];
        foreach ($raw as $id => $on) {
            $out[ (string) $id ] = true === $on;
        }
        return $out;
    }

    /**
     * @param mixed $row
     * @return array<string,mixed>
     */
    private function with_status($row, bool $on): array
    {
        $row           = is_array($row) ? $row : [];
        $row['status'] = $on ? 'active' : 'inactive';
        return $row;
    }
}
