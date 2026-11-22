<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Hustle writes of issue #1091 on the plugin-data pair, beside the
 * reads in Hustle_Pack (#898): hustle-update-content (title, texts, call to
 * action), hustle-set-schedule (on or off, start and end), hustle-set-active
 * and hustle-set-display-conditions (the visibility rules, replaced whole).
 * Each changes one row under its own before-image (rollback-operation),
 * drops Hustle's cached copy, and a schedule change resets the derived
 * schedule flags as Hustle's own save does. Off until
 * wpmcp_enable_hustle_write; Hustle's hustle_edit_module capability or
 * manage_options.
 */
final class Hustle_Write_Operations
{
    public const OPT_IN_FILTER = 'wpmcp_enable_hustle_write';

    /** The content fields an agent edits copy in. */
    private const CONTENT_KEYS = [
        'title', 'sub_title', 'main_content', 'feature_image', 'background_image', 'show_cta', 'cta_label', 'cta_url',
        'cta_target', 'cta_two_label', 'cta_two_url', 'cta_two_target', 'show_never_see_link', 'never_see_link_text',
    ];

    /** Content fields whose value is a URL. */
    private const URL_KEYS = [ 'cta_url', 'cta_two_url', 'feature_image', 'background_image' ];

    /** Content fields that hold HTML. */
    private const HTML_KEYS = [ 'main_content' ];

    /** @param \Closure(): (true|array<string, mixed>) $presence */
    public function __construct(
        private readonly Hustle_Write_Store $store,
        private readonly Hustle_Write_Snapshots $snapshots,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $write = [
            'mode'               => 'write',
            'capability'         => 'edit_posts',
            'requires'           => $this->presence,
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_hustle_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $when  = [ 'type' => [ 'string', 'null' ], 'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$' ];

        return [
            'hustle-update-content'         => $write + [
                'description'  => 'Change a Hustle module\'s copy: content {field: value} with fields title, sub_title, main_content (HTML), feature_image, background_image, show_cta, cta_label, cta_url, cta_target, cta_two_label, cta_two_url, cta_two_target, show_never_see_link, never_see_link_text. Other fields stay. Undo: rollback-operation. Disabled by default (wpmcp_enable_hustle_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'module_id' => $id, 'content' => [ 'type' => 'object', 'minProperties' => 1, 'maxProperties' => 20 ] ],
                    'required'             => [ 'module_id', 'content' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->module_refusal($args) ?? $this->content_refusal($args),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->meta(self::id($args), 'content', $this->content_after($args)),
                'handler'      => fn (array $args): array => $this->save(self::id($args), 'content', $this->content_after($args)),
            ],
            'hustle-set-schedule'           => $write + [
                'description'  => 'Schedule a Hustle module: enabled (true or false), start and end as site-local "YYYY-MM-DD HH:MM" (null for none). Undo: rollback-operation. Disabled by default (wpmcp_enable_hustle_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'module_id' => $id, 'enabled' => [ 'type' => 'boolean' ], 'start' => $when, 'end' => $when ],
                    'required'             => [ 'module_id', 'enabled' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->module_refusal($args) ?? self::schedule_refusal($args),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->meta(self::id($args), 'settings', $this->schedule_after($args)),
                'handler'      => fn (array $args): array => $this->schedule(self::id($args), $this->schedule_after($args)),
            ],
            'hustle-set-active'             => $write + [
                'description'  => 'Turn a Hustle module on or off (active). Undo: rollback-operation. Disabled by default (wpmcp_enable_hustle_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'module_id' => $id, 'active' => [ 'type' => 'boolean' ] ],
                    'required'             => [ 'module_id', 'active' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->module_refusal($args),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->module(self::id($args), true === ($args['active'] ?? null)),
                'handler'      => fn (array $args): array => $this->activate(self::id($args), true === ($args['active'] ?? null)),
            ],
            'hustle-set-display-conditions' => $write + [
                'description'  => 'Replace a Hustle module\'s display conditions (visibility, the object hustle-get-module returns). Undo: rollback-operation. Disabled by default (wpmcp_enable_hustle_write filter)',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'module_id' => $id, 'visibility' => [ 'type' => 'object', 'maxProperties' => 50 ] ],
                    'required'             => [ 'module_id', 'visibility' ],
                    'additionalProperties' => false,
                ],
                'validate'     => fn (array $args): ?array => $this->module_refusal($args) ?? $this->meta_refusal(self::id($args), 'visibility'),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->meta(self::id($args), 'visibility', Typed::map($args['visibility'] ?? null)),
                'handler'      => fn (array $args): array => $this->save(self::id($args), 'visibility', Typed::map($args['visibility'] ?? null)),
            ],
        ];
    }

    /** @param array<mixed> $args */
    private static function id(array $args): int
    {
        return Typed::int($args['module_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function module_refusal(array $args): ?array
    {
        if (! current_user_can('manage_options') && ! current_user_can(Hustle_Pack::CAP)) {
            return self::refusal('operation_denied', sprintf('This needs the %s capability (Hustle grants it to administrators).', Hustle_Pack::CAP), [ 'reason' => 'capability', 'capability' => Hustle_Pack::CAP ]);
        }
        $id = self::id($args);
        return $this->store->module_exists($id) ? null : self::refusal('module_not_found', sprintf('%d is not a Hustle module.', $id), [ 'module_id' => $id ]);
    }

    /** @return array{code: string, message: string, data: array<string, mixed>}|null */
    private function meta_refusal(int $id, string $key): ?array
    {
        return $this->store->has_meta($id, $key) ? null : self::refusal('hustle_settings_missing', sprintf('Hustle module %d has no saved %s yet; save it once in Hustle, then try again.', $id, $key), [ 'module_id' => $id ]);
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private function content_refusal(array $args): ?array
    {
        foreach (Typed::map($args['content'] ?? null) as $field => $value) {
            if (! in_array($field, self::CONTENT_KEYS, true)) {
                return self::refusal('invalid_setting', sprintf('"%s" is not a Hustle content field this op changes. Fields: %s.', $field, implode(', ', self::CONTENT_KEYS)), [ 'setting' => $field ]);
            }
            if (! is_scalar($value)) {
                return self::refusal('invalid_setting', sprintf('The value of "%s" must be text.', $field), [ 'setting' => $field ]);
            }
        }
        return $this->meta_refusal(self::id($args), 'content');
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data: array<string, mixed>}|null
     */
    private static function schedule_refusal(array $args): ?array
    {
        foreach ([ 'start', 'end' ] as $side) {
            $raw = $args[ $side ] ?? null;
            if (null !== $raw && null === Hustle_Schedule::time($raw)) {
                return self::refusal('invalid_setting', sprintf('%s must be a site-local date and time as YYYY-MM-DD HH:MM.', $side), [ 'setting' => $side ]);
            }
        }
        $schedule = Hustle_Schedule::from_args($args);
        if (null !== $schedule->start && null !== $schedule->end && $schedule->end <= $schedule->start) {
            return self::refusal('invalid_setting', 'end must be after start.', [ 'setting' => 'end' ]);
        }
        return null;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    private function content_after(array $args): array
    {
        $content = $this->store->meta(self::id($args), 'content');
        foreach (Typed::map($args['content'] ?? null) as $field => $value) {
            $text = Typed::str($value);
            if (in_array($field, self::HTML_KEYS, true)) {
                $content[ $field ] = wp_kses_post($text);
            } elseif (in_array($field, self::URL_KEYS, true)) {
                $content[ $field ] = esc_url_raw($text);
            } else {
                $content[ $field ] = sanitize_text_field($text);
            }
        }
        return $content;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    private function schedule_after(array $args): array
    {
        return Hustle_Schedule::from_args($args)->apply($this->store->meta(self::id($args), 'settings'));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function save(int $id, string $key, array $value): array
    {
        $this->store->save_meta($id, $key, $value);
        return [ 'module_id' => $id, $key => $this->store->meta($id, $key) ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function schedule(int $id, array $settings): array
    {
        $this->store->save_meta($id, 'settings', $settings);
        $this->store->reset_schedule_flags($id);
        return [ 'module_id' => $id, 'is_schedule' => $settings['is_schedule'] ?? '0', 'schedule' => $settings['schedule'] ?? [] ];
    }

    /** @return array{module_id: int, active: bool, previous: bool} */
    private function activate(int $id, bool $active): array
    {
        $previous = $this->store->active($id);
        $this->store->set_active($id, $active);
        return [ 'module_id' => $id, 'active' => $this->store->active($id), 'previous' => $previous ];
    }
}
