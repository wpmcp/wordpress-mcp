<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A template's display settings in Oxygen 6's TemplateSettings shape:
 * {type, ruleGroups, priority?, parentId?, disabled?}. ruleGroups is a list
 * of groups (any group matching applies the template) of rules (every rule
 * in a group must match), each {ruleSlug, operand, value?, ruleDynamic?,
 * ruleCategorySlug?}. Built only by Oxygen6_Settings_Validator.
 */
final class Oxygen6_Template_Settings
{
    /**
     * @param array<int,array<int,array<string,mixed>>> $rule_groups
     */
    public function __construct(
        public readonly string $type,
        public readonly array $rule_groups,
        public readonly ?int $priority = null,
        public readonly ?int $parent_id = null,
        public readonly ?bool $disabled = null
    ) {
    }

    /** @return array<string,mixed> the settings as Oxygen stores them */
    public function to_array(): array
    {
        $out = ['type' => $this->type, 'ruleGroups' => $this->rule_groups];
        if (null !== $this->priority) {
            $out['priority'] = $this->priority;
        }
        if (null !== $this->parent_id) {
            $out['parentId'] = $this->parent_id;
        }
        if (null !== $this->disabled) {
            $out['disabled'] = $this->disabled;
        }

        return $out;
    }
}
