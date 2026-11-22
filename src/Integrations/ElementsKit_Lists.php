<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The two switch lists of elementskit_options (issue #1012), and nothing
 * else of it: the user_data and settings keys, which hold API keys, never
 * reach this object.
 */
final class ElementsKit_Lists
{
    /**
     * @param array<string,mixed> $widget_list widget id => saved row (with 'status')
     * @param array<string,mixed> $module_list module id => saved row (with 'status')
     */
    public function __construct(
        public readonly array $widget_list,
        public readonly array $module_list
    ) {
    }

    /** Whether a widget is on as ElementsKit resolves it: on unless its saved row says inactive. */
    public function widget_on(string $id): bool
    {
        return ! $this->inactive($this->widget_list[ $id ] ?? null);
    }

    public function module_on(string $id): bool
    {
        return ! $this->inactive($this->module_list[ $id ] ?? null);
    }

    /** @param mixed $row */
    private function inactive($row): bool
    {
        return is_array($row) && 'inactive' === ($row['status'] ?? null);
    }
}
