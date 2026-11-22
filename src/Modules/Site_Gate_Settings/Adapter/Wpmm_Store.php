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
 * WP Maintenance Mode, also sold as LightStart (wp-maintenance-mode 2.6.26),
 * keeps everything in the wpmm_settings array.
 *
 * Content: its classic page reads design.heading (saved through
 * sanitize_text_field), design.text (wp_kses_post) and the countdown in
 * modules: countdown_status compared with === 1, countdown_start (a UTC
 * date('Y-m-d H:i:s')) and countdown_details (days, hours, minutes), the end
 * being start plus that span (calculate_backtime()). The classic page has no
 * logo. With its block based page (wpmm_new_look plus design.page_id) the
 * visitor gets that page instead, so content is refused, naming the page.
 *
 * Bypass: super admins always, then general.frontend_role on the front end
 * and general.backend_role in wp-admin (check_user_role()). general.exclude
 * mixes URL fragments and IP addresses (check_exclude()); ip_allowlist
 * replaces only the IP entries and keeps every path.
 */
final class Wpmm_Store implements Gate_Store
{
    private const OPTION = 'wpmm_settings';

    public function __construct(private readonly Field_Rules $rules)
    {
    }

    public function slug(): string
    {
        return Gate_Plugin::Wp_Maintenance_Mode->value;
    }

    public function label(): string
    {
        return 'WP Maintenance Mode (LightStart)';
    }

    /** @return array<mixed> */
    private function settings(): array
    {
        return Stored::map(get_option(self::OPTION));
    }

    private function page_id(): ?int
    {
        $design = (array) ($this->settings()['design'] ?? []);
        return (bool) get_option('wpmm_new_look') && isset($design['page_id']) ? Stored::int($design['page_id']) : null;
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $settings = $this->settings();
        $design = (array) ($settings['design'] ?? []);
        $page = $this->page_id();
        if (null !== $page) {
            return ['page_id' => $page, 'editable' => [], 'note' => 'The block based maintenance page is shown; edit its content as a page.'];
        }

        return [
            'headline' => isset($design['heading']) ? Stored::text($design['heading']) : null,
            'message' => isset($design['text']) ? Stored::text($design['text']) : null,
            'logo' => null,
            'countdown' => $this->countdown_end((array) ($settings['modules'] ?? [])),
            'editable' => ['headline', 'message', 'countdown'],
        ];
    }

    /** @param array<mixed> $modules */
    private function countdown_end(array $modules): ?string
    {
        if (1 !== Stored::int($modules['countdown_status'] ?? 0)) {
            return null;
        }
        $details = (array) ($modules['countdown_details'] ?? []);
        $span = Stored::int($details['days'] ?? 0) * DAY_IN_SECONDS + Stored::int($details['hours'] ?? 0) * HOUR_IN_SECONDS + Stored::int($details['minutes'] ?? 0) * MINUTE_IN_SECONDS;
        $end = strtotime(Stored::text($modules['countdown_start'] ?? '') . ' UTC +' . $span . ' seconds');
        return false === $end ? null : gmdate('Y-m-d\TH:i:s\Z', $end);
    }

    public function plan_content(Content_Change $change): Write_Plan|Refusal
    {
        $page = $this->page_id();
        if (null !== $page) {
            return new Refusal('wpmm_page_content', sprintf('WP Maintenance Mode shows its block based page (page %d) to visitors, so its classic heading and text are not used. Edit that page instead, with the post tools or in the block editor.', $page), ['page_id' => $page]);
        }
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), ['headline', 'message', 'countdown']);
        if (null !== $refusal) {
            return $refusal;
        }

        $settings = $this->settings();
        $design = (array) ($settings['design'] ?? []);
        if (null !== $change->headline) {
            $design['heading'] = sanitize_text_field($change->headline);
        }
        if (null !== $change->message) {
            $design['text'] = wp_kses_post($change->message);
        }
        $settings['design'] = $design;

        if (null !== $change->countdown || $change->countdown_off) {
            $settings['modules'] = self::countdown_modules((array) ($settings['modules'] ?? []), $change->countdown);
        }

        return new Write_Plan([self::OPTION => $settings]);
    }

    /**
     * The modules settings with the countdown turned on until $end, or off
     * when $end is null.
     *
     * @param array<mixed> $modules
     * @return array<mixed>
     */
    private static function countdown_modules(array $modules, ?int $end): array
    {
        $modules['countdown_status'] = null !== $end ? 1 : 0;
        if (null === $end) {
            return $modules;
        }
        $now = time();
        $left = max(0, $end - $now);
        $modules['countdown_start'] = gmdate('Y-m-d H:i:s', $now);
        // The plugin's own save stores these as numeric strings.
        $modules['countdown_details'] = [
            'days' => (string) intdiv($left, DAY_IN_SECONDS),
            'hours' => (string) intdiv($left % DAY_IN_SECONDS, HOUR_IN_SECONDS),
            'minutes' => (string) intdiv($left % HOUR_IN_SECONDS, MINUTE_IN_SECONDS),
        ];
        return $modules;
    }

    public function bypass(): Bypass_State
    {
        return $this->state_of((array) ($this->settings()['general'] ?? []));
    }

    /** An exclude entry without its "# comment", the way check_exclude() reads it. */
    private static function entry(string $item): string
    {
        $hash = strpos($item, '#');
        return trim(false === $hash ? $item : substr($item, 0, $hash));
    }

    private static function is_ip(string $item): bool
    {
        return false !== filter_var(self::entry($item), FILTER_VALIDATE_IP);
    }

    /** @param array<mixed> $general */
    private function state_of(array $general): Bypass_State
    {
        $exclude = array_values(array_filter(Stored::strings($general['exclude'] ?? []), static fn (string $item): bool => '' !== self::entry($item)));
        $ips = array_values(array_filter($exclude, [self::class, 'is_ip']));

        return new Bypass_State(
            Stored::strings($general['frontend_role'] ?? []),
            Stored::strings($general['backend_role'] ?? []),
            null,
            $ips,
            null,
            null,
            count($exclude) - count($ips),
        );
    }

    /** @return list<string> */
    public function bypass_fields(): array
    {
        return ['roles', 'backend_roles', 'ip_allowlist'];
    }

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), $this->bypass_fields())
            ?? $this->rules->role_refusal(array_merge($change->roles ?? [], $change->backend_roles ?? []));
        if (null !== $refusal) {
            return $refusal;
        }

        $settings = $this->settings();
        $general = (array) ($settings['general'] ?? []);
        if (null !== $change->roles) {
            $general['frontend_role'] = $change->roles;
        }
        if (null !== $change->backend_roles) {
            $general['backend_role'] = $change->backend_roles;
        }
        if (null !== $change->ips) {
            $paths = array_values(array_filter(Stored::strings($general['exclude'] ?? []), static fn (string $item): bool => ! self::is_ip($item)));
            $general['exclude'] = array_merge($paths, $change->ips);
        }
        $settings['general'] = $general;

        return new Write_Plan([self::OPTION => $settings], null, null, $this->state_of($general));
    }

    public function lets_through(Bypass_State $state): bool
    {
        if (is_super_admin()) {
            return true;
        }
        $roles = $this->rules->current_roles();
        $extra = is_multisite() ? ['administrator'] : [];
        return $this->rules->intersects($roles, array_merge($state->roles ?? [], $extra))
            && $this->rules->intersects($roles, array_merge($state->backend_roles ?? [], $extra));
    }
}
