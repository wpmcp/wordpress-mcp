<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One planned ElementsKit switch batch (issue #1012): either a refusal, or
 * what changes. A list or option that does not change is null, so nothing
 * is written for it.
 */
final class ElementsKit_Plan
{
    /**
     * @param array{code: string, message: string, data: array<string,mixed>}|null $error
     * @param array<string,bool>        $changed_widgets widget id => new state, only rows that change
     * @param array<string,bool>        $changed_modules module id => new state, only rows that change
     * @param array<string,mixed>|null  $widget_list     the new widget_list, or null when unchanged
     * @param array<string,mixed>|null  $module_list     the new module_list, or null when unchanged
     * @param string[]|null             $disabled        the new elementor_disabled_elements, or null when unchanged
     * @param string[]                  $added           names the batch adds to elementor_disabled_elements
     * @param string[]                  $removed         names the batch removes from it
     */
    public function __construct(
        public readonly ?array $error,
        public readonly array $changed_widgets = [],
        public readonly array $changed_modules = [],
        public readonly ?array $widget_list = null,
        public readonly ?array $module_list = null,
        public readonly ?array $disabled = null,
        public readonly array $added = [],
        public readonly array $removed = []
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function refused(string $code, string $message, array $data = []): self
    {
        return new self([ 'code' => $code, 'message' => $message, 'data' => $data ]);
    }

    public function has_changes(): bool
    {
        return null === $this->error
            && (null !== $this->widget_list || null !== $this->module_list || null !== $this->disabled);
    }
}
