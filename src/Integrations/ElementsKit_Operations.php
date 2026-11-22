<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ElementsKit ops on the theme dispatcher pair (issue #1012), paid tier:
 *
 *  - list-elementskit-widgets: the widget catalog (ElementsKit's own widget
 *    list, Pro placeholders included and marked unavailable) with each
 *    widget's on/off state, its Elementor names and whether Elementor's
 *    Element Manager has it disabled;
 *  - get-elementskit-modules: widget and module on/off state, from the
 *    widget_list and module_list rows of elementskit_options only;
 *  - set-elementskit-modules: switch widgets and optional modules on or
 *    off. A widget switch also updates elementor_disabled_elements the way
 *    ElementsKit's element-manager sync does, and both options go under one
 *    option_set snapshot. The whole batch is checked before any write.
 *
 * No read or write answer carries the user_data or settings keys of
 * elementskit_options (API keys).
 */
final class ElementsKit_Operations
{
    public const SUITE = 'elementskit';

    public function __construct(
        private readonly ElementsKit_Catalog $catalog,
        private readonly ElementsKit_Store $store,
        private readonly ElementsKit_Planner $planner,
        private readonly ElementsKit_Snapshot $snapshot,
        private readonly ElementsKit_Element_Sync $sync
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $requires = fn () => $this->requirement();
        $switches = [
            'type'                 => 'object',
            'maxProperties'        => ElementsKit_Planner::MAX_SWITCHES,
            'additionalProperties' => [ 'type' => 'boolean' ],
        ];

        return [
            'list-elementskit-widgets' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => 'List the ElementsKit widgets from ElementsKit\'s own widget list, switched-off ones included: id, title, category, package, available (false for an ElementsKit Pro placeholder), active, the Elementor widget names it registers as, and whether Elementor\'s Element Manager has it disabled',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => fn (): array => $this->widgets(),
            ],
            'get-elementskit-modules'  => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => 'Read every ElementsKit widget and optional module on/off state from the widget_list and module_list rows of elementskit_options, resolved as ElementsKit resolves them (an unsaved row is on). Also lists the required modules (always on) and the Pro-only rows',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => fn (): array => $this->modules(),
            ],
            'set-elementskit-modules'  => [
                'mode'         => 'write',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => sprintf('Switch ElementsKit widgets (widgets) and optional modules (modules) on (true) or off (false), up to %d per call. A widget switch also updates Elementor\'s Element Manager list (elementor_disabled_elements) as ElementsKit\'s own sync does, so a widget switched off disappears from every page using it. An unknown id, a required module or a Pro-only row refuses the whole batch before any write. Only rows that change are written; ElementsKit\'s other settings are kept as stored. Both options are snapshotted together for rollback-operation', ElementsKit_Planner::MAX_SWITCHES),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'widgets' => $switches,
                        'modules' => $switches,
                    ],
                ],
                'requires'     => $requires,
                'validate'     => fn (array $args): ?array => $this->plan($args)->error,
                'snapshot'     => fn (array $args): ?array => $this->snapshot->target($this->plan($args)),
                'handler'      => fn (array $args): array => $this->write($args),
            ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array<string,mixed>} */
    public function requirement()
    {
        if (! $this->catalog->active()) {
            return Plugin_Refusal::make('addon_suite_inactive', ElementsKit_Catalog::PRODUCT, ElementsKit_Catalog::PLUGIN_FILE);
        }
        return true;
    }

    /** @return array<string,mixed> */
    public function widgets(): array
    {
        $lists      = $this->store->lists();
        $disabled   = $this->store->disabled_elements();
        $registered = $this->catalog->registered_widget_names();
        $rows       = [];
        foreach ($this->catalog->widgets() as $id => $row) {
            $available = $this->catalog->available($row);
            $names     = array_values(array_filter(
                $this->sync->names($id),
                static fn (string $name): bool => isset($registered[ $name ]) || in_array($name, $disabled, true)
            ));
            $rows[ $id ] = [
                'id'                    => $id,
                'title'                 => (string) ($row['title'] ?? $id),
                'category'              => (string) ($row['widget-category'] ?? ''),
                'package'               => (string) ($row['package'] ?? ''),
                'available'             => $available,
                'active'                => $available && $lists->widget_on($id),
                'elementor_names'       => $names,
                'disabled_in_elementor' => [] !== array_intersect($this->sync->names($id), $disabled),
            ];
        }
        ksort($rows);
        return [ 'suite' => self::SUITE, 'count' => count($rows), 'widgets' => array_values($rows) ];
    }

    /** @return array<string,mixed> */
    public function modules(): array
    {
        return [ 'suite' => self::SUITE ] + $this->state();
    }

    /** @return array<string,mixed> */
    public function write(array $args): array
    {
        $plan = $this->plan($args);
        if (null !== $plan->error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused($plan->error['code'], $plan->error['message'], $plan->error['data']);
        }
        if ($plan->has_changes()) {
            $this->store->save($plan);
        }
        return [
            'suite'                       => self::SUITE,
            'changed'                     => [ 'widgets' => $plan->changed_widgets, 'modules' => $plan->changed_modules ],
            'elementor_disabled_elements' => [ 'added' => $plan->added, 'removed' => $plan->removed ],
        ] + $this->state();
    }

    private function plan(array $args): ElementsKit_Plan
    {
        return $this->planner->plan((array) ($args['widgets'] ?? []), (array) ($args['modules'] ?? []));
    }

    /** @return array{widgets: array<string,bool>, modules: array<string,bool>, required_modules: string[], pro_only: array{widgets: string[], modules: string[]}} */
    private function state(): array
    {
        $lists   = $this->store->lists();
        $widgets = [];
        $modules = [];
        $pro     = [ 'widgets' => [], 'modules' => [] ];
        foreach ($this->catalog->widgets() as $id => $row) {
            if ($this->catalog->available($row)) {
                $widgets[ $id ] = $lists->widget_on($id);
            } else {
                $pro['widgets'][] = $id;
            }
        }
        foreach ($this->catalog->optional_modules() as $id => $row) {
            if ($this->catalog->available($row)) {
                $modules[ $id ] = $lists->module_on($id);
            } else {
                $pro['modules'][] = $id;
            }
        }
        ksort($widgets);
        ksort($modules);
        sort($pro['widgets']);
        sort($pro['modules']);
        $required = array_map('strval', array_keys($this->catalog->required_modules()));
        sort($required);

        return [ 'widgets' => $widgets, 'modules' => $modules, 'required_modules' => $required, 'pro_only' => $pro ];
    }
}
