<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Loads Oxygen 6's template types and conditions from its own registry.
 * Oxygen registers them on 'breakdance_register_template_types_and_conditions'
 * (run through its bdox_run_action(), which also fires the oxygen_ alias)
 * whenever it needs them; this runs that once per request if Oxygen has not,
 * then reads ThemelessController. The `wpmcp_oxygen_vocabulary` filter sees
 * (and may replace) the raw registry, {types: categories, conditions}.
 */
final class Oxygen6_Vocabulary_Source
{
    private const ACTION = 'breakdance_register_template_types_and_conditions';

    private const CONTROLLER = '\\Breakdance\\Themeless\\ThemelessController';

    /** The vocabulary, or null when Oxygen's registry is not loaded. */
    public function load(): ?Oxygen6_Vocabulary
    {
        $registry = null;
        if (class_exists(self::CONTROLLER)) {
            if (0 === did_action(self::ACTION) && function_exists('bdox_run_action')) {
                \bdox_run_action(self::ACTION);
            }
            $instance   = [self::CONTROLLER, 'getInstance'];
            $controller = is_callable($instance) ? call_user_func($instance) : null;
            $registry   = ['types' => Oxygen6_Values::at($controller, 'templateTypeCategories'), 'conditions' => Oxygen6_Values::at($controller, 'conditions')];
        }

        $registry = apply_filters('wpmcp_oxygen_vocabulary', $registry);
        if (! is_array($registry)) {
            return null;
        }

        return Oxygen6_Vocabulary::from_registry($registry['types'] ?? [], $registry['conditions'] ?? []);
    }

    public static function not_loaded(): \WP_Error
    {
        return new \WP_Error('builder_not_loaded', "Oxygen's template types and conditions are not loaded on this site, so display conditions cannot be checked. Activate Oxygen 6 and try again.");
    }
}
