<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the two options an ElementsKit switch touches (issue
 * #1012): elementskit_options and Elementor's elementor_disabled_elements.
 *
 * elementskit_options holds widget_list, module_list, user_data and
 * settings. Only the two lists ever leave this class (as ElementsKit_Lists);
 * user_data and settings hold API keys and are never read out. A write
 * replaces only the lists the plan changed and keeps every other key, its
 * value and the key order exactly as stored.
 *
 * The write order matches ElementsKit's own settings save: Elementor's list
 * first, then elementskit_options. ElementsKit hooks
 * update_option_elementor_disabled_elements and rewrites widget_list from
 * that list; the second write then puts the planned lists over the
 * pre-write option, so the final state is the plan, whatever the hook did.
 */
final class ElementsKit_Store
{
    public const OPTION   = 'elementskit_options';
    public const DISABLED = 'elementor_disabled_elements';

    private const WIDGET_LIST = 'widget_list';
    private const MODULE_LIST = 'module_list';

    /** Whether elementskit_options is unsaved or an array (anything else is not ElementsKit's format). */
    public function readable(): bool
    {
        $raw = get_option(self::OPTION, false);
        return false === $raw || is_array($raw);
    }

    public function lists(): ElementsKit_Lists
    {
        $raw = get_option(self::OPTION, false);
        $raw = is_array($raw) ? $raw : [];
        return new ElementsKit_Lists($this->list_of($raw, self::WIDGET_LIST), $this->list_of($raw, self::MODULE_LIST));
    }

    /** @return string[] */
    public function disabled_elements(): array
    {
        return array_values(array_map('strval', array_filter((array) get_option(self::DISABLED, []), 'is_scalar')));
    }

    public function save(ElementsKit_Plan $plan): void
    {
        $before = get_option(self::OPTION, false);
        if (null !== $plan->disabled) {
            update_option(self::DISABLED, $plan->disabled);
        }
        if (null === $plan->widget_list && null === $plan->module_list) {
            // Only Elementor's list changed. Put back any widget_list the
            // ElementsKit hook derived from it, so this write stays the plan.
            if (is_array($before) && get_option(self::OPTION, false) !== $before) {
                update_option(self::OPTION, $before);
            }
            return;
        }
        $options = is_array($before) ? $before : [];
        if (null !== $plan->widget_list) {
            $options[ self::WIDGET_LIST ] = $plan->widget_list;
        }
        if (null !== $plan->module_list) {
            $options[ self::MODULE_LIST ] = $plan->module_list;
        }
        update_option(self::OPTION, $options);
    }

    /** @return array<string,mixed> */
    private function list_of(array $raw, string $key): array
    {
        $list = $raw[ $key ] ?? [];
        return is_array($list) ? $list : [];
    }
}
