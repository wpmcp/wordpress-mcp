<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Checks template settings against Oxygen 6's own vocabulary before they are
 * stored, the way its request matcher (Breakdance\Themeless\doesTemplateApply()
 * and doesRuleApply()) and template manager read them:
 *
 * - `type` is a registered template type slug;
 * - each rule names a registered condition that supports templating, is
 *   available for that type ('ALL', the type itself, or for a header or
 *   footer any condition with the 'everywhere' type, as Oxygen's manager
 *   adds 'everywhere' to every condition for those), uses one of its
 *   operands, and has a value unless the operand is 'is empty' or 'is not
 *   empty' (a string, or a list of strings or {text, value} items);
 * - `priority` is an integer, `disabled` a boolean and `parentId` (templates
 *   only) another Oxygen template.
 *
 * The Custom PHP condition evaluates its value as PHP on every request, so it
 * is never written here; fallback templates are Oxygen's own and not set.
 *
 * @throws \InvalidArgumentException with the reason, on anything else
 */
final class Oxygen6_Settings_Validator
{
    public const CUSTOM_PHP = 'custom-php';

    private const KEYS = ['type', 'ruleGroups', 'priority', 'parentId', 'disabled'];

    private const RULE_KEYS = ['ruleSlug', 'operand', 'value', 'ruleDynamic', 'ruleCategorySlug'];

    private const NO_VALUE_OPERANDS = ['is empty', 'is not empty'];

    public function __construct(
        private readonly Oxygen6_Vocabulary $vocabulary,
        private readonly Oxygen6_Store $store
    ) {
    }

    /**
     * @param mixed $input the settings object from the call
     * @param string $kind 'template', 'header' or 'footer'
     * @param int $self_id the template being changed (0 for a new one)
     */
    public function validate(mixed $input, string $kind, int $self_id = 0): Oxygen6_Template_Settings
    {
        if (! is_array($input) || ([] !== $input && array_is_list($input))) {
            Oxygen6_Request_Error::raise('settings must be an object: {type, ruleGroups?, priority?, parentId?, disabled?}.');
        }
        $unknown = array_diff(array_keys($input), self::KEYS);
        if ([] !== $unknown) {
            Oxygen6_Request_Error::raise('settings takes type, ruleGroups, priority, parentId and disabled; got \'' . implode("', '", $unknown) . "'.");
        }

        $type = $input['type'] ?? null;
        if (! is_string($type) || '' === $type) {
            Oxygen6_Request_Error::raise("settings needs type: one of Oxygen's template type slugs (read get-builder-content scope conditions).");
        }
        if (! $this->vocabulary->has_type($type)) {
            Oxygen6_Request_Error::raise("Unknown template type '{$type}'; read get-builder-content scope conditions for the types Oxygen registers.");
        }

        $groups = $input['ruleGroups'] ?? [];
        if (! is_array($groups) || ! array_is_list($groups)) {
            Oxygen6_Request_Error::raise('ruleGroups must be a list of rule groups, each a list of rules.');
        }
        $rule_groups = [];
        foreach ($groups as $group) {
            if (! is_array($group) || [] === $group || ! array_is_list($group)) {
                Oxygen6_Request_Error::raise('Every entry of ruleGroups must be a non-empty list of rules (a group matches when all its rules do).');
            }
            $rules = [];
            foreach ($group as $rule) {
                $rules[] = $this->rule($rule, $type, $kind);
            }
            $rule_groups[] = $rules;
        }

        $priority = $input['priority'] ?? null;
        if (null !== $priority && ! is_int($priority)) {
            Oxygen6_Request_Error::raise('priority must be an integer (Oxygen applies the highest-priority template that matches).');
        }
        $disabled = $input['disabled'] ?? null;
        if (null !== $disabled && ! is_bool($disabled)) {
            Oxygen6_Request_Error::raise('disabled must be true or false.');
        }

        return new Oxygen6_Template_Settings($type, $rule_groups, $priority, $this->parent($input['parentId'] ?? null, $kind, $self_id), $disabled);
    }

    /** @return array<string,mixed> */
    private function rule(mixed $rule, string $type, string $kind): array
    {
        if (! is_array($rule) || array_is_list($rule)) {
            Oxygen6_Request_Error::raise('Every rule must be an object: {ruleSlug, operand, value?, ruleDynamic?}.');
        }
        $unknown = array_diff(array_keys($rule), self::RULE_KEYS);
        if ([] !== $unknown) {
            Oxygen6_Request_Error::raise('A rule takes ruleSlug, operand, value, ruleDynamic and ruleCategorySlug; got \'' . implode("', '", $unknown) . "'.");
        }

        $slug = $rule['ruleSlug'] ?? null;
        if (! is_string($slug) || '' === $slug) {
            Oxygen6_Request_Error::raise('Every rule needs ruleSlug: a condition slug from get-builder-content scope conditions.');
        }
        if (self::CUSTOM_PHP === $slug) {
            Oxygen6_Request_Error::raise('The custom-php condition runs its value as PHP on every request, so it is not set through this tool; add it in the Oxygen editor.');
        }
        $condition = $this->vocabulary->condition($slug);
        if (null === $condition) {
            Oxygen6_Request_Error::raise("Unknown condition '{$slug}' (or one Oxygen does not offer for templates); read get-builder-content scope conditions.");
        }

        $available = $condition['available_for'];
        $headerish = in_array($kind, ['header', 'footer'], true) && 'everywhere' === $type;
        if (! $headerish && ! in_array('ALL', $available, true) && ! in_array($type, $available, true)) {
            Oxygen6_Request_Error::raise("Condition '{$slug}' is not available for template type '{$type}'; it is for " . implode(', ', $available) . '.');
        }

        $operand = $rule['operand'] ?? null;
        if (! is_string($operand) || ! in_array($operand, $condition['operands'], true)) {
            $got = is_string($operand) ? $operand : '';
            Oxygen6_Request_Error::raise("Condition '{$slug}' takes operand '" . implode("' or '", $condition['operands']) . "'; got '{$got}'.");
        }

        $out = ['ruleSlug' => $slug, 'operand' => $operand];
        if (array_key_exists('value', $rule)) {
            $out['value'] = $this->value($rule['value'], $slug);
        } elseif (! in_array($operand, self::NO_VALUE_OPERANDS, true)) {
            Oxygen6_Request_Error::raise("The '{$slug}' rule needs a value with operand '{$operand}'.");
        }
        foreach (['ruleDynamic', 'ruleCategorySlug'] as $key) {
            if (array_key_exists($key, $rule)) {
                if (! is_string($rule[ $key ])) {
                    Oxygen6_Request_Error::raise("{$key} must be a string.");
                }
                $out[ $key ] = $rule[ $key ];
            }
        }

        return $out;
    }

    /** @return string|list<string|array{text?:string|null,value:string}> */
    private function value(mixed $value, string $slug): string|array
    {
        if (is_string($value)) {
            return $value;
        }
        $bad = "The '{$slug}' rule's value must be a string, or a list of strings or {text, value} items.";
        if (! is_array($value) || ! array_is_list($value)) {
            Oxygen6_Request_Error::raise($bad);
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = is_string($item) ? $item : $this->value_item($item, $bad);
        }

        return $out;
    }

    /**
     * One {text?, value} item of a rule's value list, its keys kept in
     * their order; a null text is accepted as it is.
     *
     * @return array{text?:string|null,value:string}
     */
    private function value_item(mixed $item, string $bad): array
    {
        if (! is_array($item)) {
            Oxygen6_Request_Error::raise($bad);
        }
        $out = [];
        foreach ($item as $key => $field) {
            if ('value' === $key && is_string($field)) {
                $out['value'] = $field;
            } elseif ('text' === $key && (null === $field || is_string($field))) {
                $out['text'] = $field;
            } else {
                Oxygen6_Request_Error::raise($bad);
            }
        }
        if (! isset($out['value'])) {
            Oxygen6_Request_Error::raise($bad);
        }

        return $out;
    }

    private function parent(mixed $parent, string $kind, int $self_id): ?int
    {
        if (null === $parent) {
            return null;
        }
        if ('template' !== $kind) {
            Oxygen6_Request_Error::raise('parentId (inherit design from) is only for templates.');
        }
        $post = is_int($parent) ? $this->store->post($parent) : null;
        if (null === $post || Oxygen6_Store::KINDS['template'] !== $post->post_type || 'publish' !== $post->post_status || $parent === $self_id) {
            Oxygen6_Request_Error::raise('parentId must name another published Oxygen template (the one this template inherits its design from).');
        }

        return $parent;
    }
}
