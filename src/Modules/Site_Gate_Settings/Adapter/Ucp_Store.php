<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_State;
use WPMCP\Modules\Site_Gate_Settings\Value\Content_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Gate_Plugin;
use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;
use WPMCP\Modules\Site_Gate_Settings\Value\Stored;
use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Under Construction (under-construction-page 5.83) keeps everything in the
 * ucp_options array: heading1 and content are the page text (its own save
 * runs both through wp_kses_post), whitelisted_roles and whitelisted_users
 * are who walks past it. Its free themes carry their own artwork, and its
 * end_date turns the page off rather than showing a countdown, so neither a
 * logo nor a countdown is editable here. A user passes when their first
 * role is whitelisted or their id is (UCP::user_has_role()).
 */
final class Ucp_Store implements Gate_Store
{
    private const OPTION = 'ucp_options';

    public function __construct(private readonly Field_Rules $rules)
    {
    }

    public function slug(): string
    {
        return Gate_Plugin::Under_Construction->value;
    }

    public function label(): string
    {
        return 'Under Construction';
    }

    /** @return array<mixed> */
    private function options(): array
    {
        return Stored::map(get_option(self::OPTION));
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $options = $this->options();
        return [
            'headline' => isset($options['heading1']) ? Stored::text($options['heading1']) : null,
            'message' => isset($options['content']) ? Stored::text($options['content']) : null,
            'logo' => null,
            'countdown' => null,
            'editable' => ['headline', 'message'],
        ];
    }

    public function plan_content(Content_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), ['headline', 'message']);
        if (null !== $refusal) {
            return $refusal;
        }
        $options = $this->options();
        if (null !== $change->headline) {
            $options['heading1'] = trim(wp_kses_post($change->headline));
        }
        if (null !== $change->message) {
            $options['content'] = trim(wp_kses_post($change->message));
        }
        return new Write_Plan([self::OPTION => $options]);
    }

    public function bypass(): Bypass_State
    {
        return $this->state_of($this->options());
    }

    /** @param array<mixed> $options */
    private function state_of(array $options): Bypass_State
    {
        // A missing list means the plugin's default: administrators only.
        return new Bypass_State(
            isset($options['whitelisted_roles']) ? Stored::strings($options['whitelisted_roles']) : ['administrator'],
            null,
            Stored::ints($options['whitelisted_users'] ?? []),
        );
    }

    /** @return list<string> */
    public function bypass_fields(): array
    {
        return ['roles', 'users'];
    }

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), $this->bypass_fields())
            ?? $this->rules->role_refusal($change->roles ?? [])
            ?? $this->rules->users_refusal($change->users ?? []);
        if (null !== $refusal) {
            return $refusal;
        }
        $options = $this->options();
        if (null !== $change->roles) {
            $options['whitelisted_roles'] = $change->roles;
        }
        if (null !== $change->users) {
            // The plugin's user picker posts ids as strings.
            $options['whitelisted_users'] = array_map('strval', $change->users);
        }
        return new Write_Plan([self::OPTION => $options], null, null, $this->state_of($options));
    }

    public function lets_through(Bypass_State $state): bool
    {
        $user = wp_get_current_user();
        $roles = array_values(array_map('strval', $user->roles));
        $first = [] !== $roles ? $roles[0] : 'guest';
        return in_array($first, $state->roles ?? [], true) || in_array($user->ID, $state->users ?? [], true);
    }
}
