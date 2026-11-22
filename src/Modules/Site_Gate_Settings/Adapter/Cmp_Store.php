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
 * CMP (cmp-coming-soon-maintenance 4.1.22) keeps each setting in its own
 * option, saved by cmp-settings.php and inc/settings/settings-counter.php.
 *
 * Content: niteoCS_body_title (sanitize_text_field, default "SOMETHING IS
 * HAPPENING!"), niteoCS_body (HTML), niteoCS_logo_type ('text', 'graphic'
 * or 'disabled') with niteoCS_logo_id (an attachment id), and the countdown
 * as niteoCS_counter ('1' shows it, the default) and niteoCS_counter_date
 * (a Unix time).
 *
 * Bypass: niteoCS_roles (a JSON list) plus administrator, always
 * (cmp_roles_filter()), and the bypass link ?cmp_bypass=<niteoCS_bypass_id>,
 * honoured while niteoCS_bypass is '1'. The free plugin has no IP list.
 *
 * Every plan names exactly the options it changes, so the undo point is an
 * option_set over those and nothing else.
 */
final class Cmp_Store implements Gate_Store
{
    private const DEFAULT_TITLE = 'SOMETHING IS HAPPENING!';

    public function __construct(private readonly Field_Rules $rules)
    {
    }

    public function slug(): string
    {
        return Gate_Plugin::Cmp->value;
    }

    public function label(): string
    {
        return 'CMP';
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $type = Stored::text(get_option('niteoCS_logo_type', 'text'));
        $date = absint(Stored::text(get_option('niteoCS_counter_date')));

        return [
            'headline' => stripslashes(Stored::text(get_option('niteoCS_body_title', self::DEFAULT_TITLE))),
            'message' => Stored::text(get_option('niteoCS_body', '')),
            'logo' => ['type' => $type, 'id' => 'graphic' === $type ? absint(Stored::text(get_option('niteoCS_logo_id'))) : null, 'text' => 'text' === $type ? Stored::text(get_option('niteoCS_text_logo', '')) : null],
            'countdown' => '1' === Stored::text(get_option('niteoCS_counter', '1')) && $date > 0 ? gmdate('Y-m-d\TH:i:s\Z', $date) : null,
            'editable' => ['headline', 'message', 'logo', 'countdown'],
        ];
    }

    public function plan_content(Content_Change $change): Write_Plan|Refusal
    {
        $refusal = null === $change->logo ? null : $this->rules->logo_refusal($change->logo);
        if (null !== $refusal) {
            return $refusal;
        }
        $options = [];
        if (null !== $change->headline) {
            $options['niteoCS_body_title'] = sanitize_text_field($change->headline);
        }
        if (null !== $change->message) {
            $options['niteoCS_body'] = wp_kses_post($change->message);
        }
        if (null !== $change->logo) {
            if ($change->logo > 0) {
                $options['niteoCS_logo_id'] = (string) $change->logo;
            }
            $options['niteoCS_logo_type'] = $change->logo > 0 ? 'graphic' : 'disabled';
        }
        if (null !== $change->countdown) {
            $options['niteoCS_counter'] = '1';
            $options['niteoCS_counter_date'] = (string) $change->countdown;
        } elseif ($change->countdown_off) {
            $options['niteoCS_counter'] = '0';
        }
        return new Write_Plan($options);
    }

    public function bypass(): Bypass_State
    {
        return new Bypass_State(
            self::roles(Stored::text(get_option('niteoCS_roles', '[]'))),
            null,
            null,
            null,
            '1' === Stored::text(get_option('niteoCS_bypass', '0')) && '' !== Stored::text(get_option('niteoCS_bypass_id', '')),
        );
    }

    /** @return list<string> */
    private static function roles(string $json): array
    {
        $roles = json_decode($json, true);
        return is_array($roles) ? Stored::strings($roles) : [];
    }

    /** @return list<string> */
    public function bypass_fields(): array
    {
        return ['roles', 'bypass_link'];
    }

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), $this->bypass_fields())
            ?? $this->rules->role_refusal($change->roles ?? []);
        if (null !== $refusal) {
            return $refusal;
        }
        $options = [];
        $roles = $this->bypass()->roles;
        $link = true === $this->bypass()->link_set;
        if (null !== $change->roles) {
            $options['niteoCS_roles'] = (string) wp_json_encode($change->roles);
            $roles = $change->roles;
        }
        if (null !== $change->bypass_link) {
            if ('' === $change->bypass_link) {
                $options['niteoCS_bypass'] = '0';
            } else {
                $options['niteoCS_bypass_id'] = $change->bypass_link;
                $options['niteoCS_bypass'] = '1';
            }
            $link = '' !== $change->bypass_link;
        }
        return new Write_Plan($options, null, null, new Bypass_State($roles, null, null, null, $link));
    }

    public function lets_through(Bypass_State $state): bool
    {
        return $this->rules->intersects($this->rules->current_roles(), array_merge($state->roles ?? [], ['administrator']));
    }
}
