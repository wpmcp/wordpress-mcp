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
 * Maintenance (maintenance 4.33) keeps everything in mtnc-options['options'].
 *
 * Content: its page is built in its designer from modules of the theme named
 * by theme_global, stored as a stdClass tree (its save json_decode()s the
 * designer's JSON without assoc, and frontend/index.php reads it with ->).
 * The headline is the first header module's groups->header->fields->text,
 * the message the first content module's groups->col1->fields->text and
 * the logo the first logo module's groups->logo->fields->logo (a URL). Only
 * those values change; every other node keeps its type. A theme without
 * that module is refused, naming the designer. Countdowns are Pro only.
 *
 * Bypass: load_maintenance_page() lets logged-in users through when
 * show_logged_in is '1', an address listed one per line in ip_whitelist, and
 * anyone who opened the site with ?<direct_access_link>. The free settings
 * screen shows these as Pro and its own next save puts them back to their
 * defaults, which the result says.
 */
final class Maintenance_Store implements Gate_Store
{
    private const OPTION = 'mtnc-options';

    /** Content field => [module type, group, field]. */
    private const MODULES = [
        'headline' => ['header', 'header', 'text'],
        'message' => ['content', 'col1', 'text'],
        'logo' => ['logo', 'logo', 'logo'],
    ];

    public function __construct(private readonly Field_Rules $rules)
    {
    }

    public function slug(): string
    {
        return Gate_Plugin::Maintenance->value;
    }

    public function label(): string
    {
        return 'Maintenance';
    }

    /** @return array<mixed> the whole stored option, a fresh copy. */
    private function stored(): array
    {
        $stored = get_option(self::OPTION);
        return is_array($stored) ? array_map([self::class, 'deep_copy'], $stored) : [];
    }

    /**
     * @param array<mixed> $stored
     * @return array<mixed>
     */
    private static function options(array $stored): array
    {
        return Stored::map($stored['options'] ?? null);
    }

    /**
     * A copy whose stdClass nodes are new objects, so planning a change never
     * touches the value WordPress handed out. Any value: the stored tree mixes
     * arrays, objects and scalars.
     */
    private static function deep_copy(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'deep_copy'], $value);
        }
        if (! $value instanceof \stdClass) {
            return $value;
        }
        $copy = new \stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            $copy->{$key} = self::deep_copy($item);
        }
        return $copy;
    }

    /**
     * The active designer theme, or null.
     *
     * @param array<mixed> $options
     */
    private static function theme(array $options): ?\stdClass
    {
        $id = Stored::text($options['theme_global'] ?? '');
        $theme = Stored::map($options['themes'] ?? null)[ $id ] ?? null;
        return $theme instanceof \stdClass ? $theme : null;
    }

    /** The field node of the first module of a type, in the page's module order. */
    private static function field_node(\stdClass $theme, string $content_field): ?\stdClass
    {
        [$type, $group, $field] = self::MODULES[ $content_field ];
        $modules = Stored::prop($theme, 'modules');
        if (! $modules instanceof \stdClass) {
            return null;
        }
        $order = Stored::prop($theme, 'modules_order');
        $order = is_array($order) ? $order : array_keys(get_object_vars($modules));
        foreach ($order as $id) {
            $module = Stored::prop($modules, Stored::text($id));
            if ($module instanceof \stdClass && $type === Stored::prop($module, 'type')) {
                $node = Stored::prop(Stored::prop(Stored::prop(Stored::prop($module, 'groups'), $group), 'fields'), $field);
                return $node instanceof \stdClass ? $node : null;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $theme = self::theme(self::options($this->stored()));
        $value = static function (string $field) use ($theme): ?string {
            $node = null === $theme ? null : self::field_node($theme, $field);
            return null === $node ? null : Stored::text(Stored::prop($node, 'value') ?? '');
        };
        $logo = $value('logo');

        return [
            'headline' => $value('headline'),
            'message' => $value('message'),
            'logo' => null === $logo ? null : ['url' => $logo],
            'countdown' => null,
            'editable' => ['headline', 'message', 'logo'],
        ];
    }

    public function plan_content(Content_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), array_keys(self::MODULES));
        if (null !== $refusal) {
            return $refusal;
        }
        $stored = $this->stored();
        $theme = self::theme(self::options($stored));
        $values = [
            'headline' => null === $change->headline ? null : wp_kses_post($change->headline),
            'message' => null === $change->message ? null : wp_kses_post($change->message),
            'logo' => null === $change->logo ? null : (0 === $change->logo ? '' : esc_url_raw((string) wp_get_attachment_url($change->logo))),
        ];
        foreach (array_filter($values, static fn (?string $v): bool => null !== $v) as $field => $value) {
            $node = null === $theme ? null : self::field_node($theme, $field);
            if (null === $node) {
                return new Refusal('content_field_missing', sprintf('The active Maintenance design has no %s module, so there is no %s to edit. Add the module in Maintenance\'s designer (its Design tab in wp-admin) first.', self::MODULES[ $field ][0], $field), ['field' => $field]);
            }
            $node->value = $value;
        }
        // $theme is the node inside $stored, so the values above are already in place.
        return new Write_Plan([self::OPTION => $stored]);
    }

    public function bypass(): Bypass_State
    {
        return $this->state_of(self::options($this->stored()));
    }

    /** @param array<mixed> $options */
    private function state_of(array $options): Bypass_State
    {
        $lines = array_map('trim', explode("\n", Stored::text($options['ip_whitelist'] ?? '')));
        // Truthy lines, as the plugin's own filter keeps them.
        $ips = array_values(array_filter($lines, static fn (string $line): bool => (bool) $line));

        return new Bypass_State(
            null,
            null,
            null,
            $ips,
            '' !== Stored::text($options['direct_access_link'] ?? ''),
            '1' === Stored::text($options['show_logged_in'] ?? ''),
        );
    }

    /** @return list<string> */
    public function bypass_fields(): array
    {
        return ['logged_in_bypass', 'ip_allowlist', 'bypass_link'];
    }

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), $this->bypass_fields());
        if (null !== $refusal) {
            return $refusal;
        }
        $stored = $this->stored();
        $options = self::options($stored);
        if (null !== $change->logged_in) {
            $options['show_logged_in'] = $change->logged_in ? '1' : '0';
        }
        if (null !== $change->ips) {
            $options['ip_whitelist'] = implode("\n", $change->ips);
        }
        if (null !== $change->bypass_link) {
            $options['direct_access_link'] = $change->bypass_link;
        }
        $stored['options'] = $options;

        return new Write_Plan(
            [self::OPTION => $stored],
            null,
            null,
            $this->state_of($options),
            ['Maintenance shows these access settings as Pro on its free settings screen, and its own next settings save puts them back to its defaults (logged-in users pass, no IP addresses, no link).'],
        );
    }

    public function lets_through(Bypass_State $state): bool
    {
        // The acting user is signed in; only the logged-in bypass is certain to cover them.
        return true === $state->logged_in;
    }
}
