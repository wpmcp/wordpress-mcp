<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Cart Abandonment Recovery ops (issue #1096) for the plugin-data pair.
 *
 * Reads, at manage_woocommerce (the plugin's own screens):
 *  - get-settings: tracking, when a cart counts as abandoned and as lost,
 *    excluded roles and order statuses, sender, coupon, GDPR and webhook
 *    switches. The Zapier webhook URL is a secret and never returned, only
 *    whether one is set;
 *  - get-stats: recoverable (abandoned), recovered and lost carts with their
 *    totals and the recovery rate, for a date range (default the last 7
 *    days), as the plugin's dashboard counts them;
 *  - list-carts: carts newest first with the customer email MASKED and no
 *    name, phone or address;
 *  - list-templates and get-template: the follow-up emails with their delay,
 *    sent and queued counts; one in full with its body and coupon settings.
 * Writes, off until wpmcp_enable_cart_abandonment_write, at manage_options
 * (their undo restores a raw row or options), each undone with
 * rollback-operation:
 *  - update-template: name, subject, body (post HTML), active and the delay
 *    of one follow-up email. Emails already queued keep their send time;
 *  - update-timing: minutes until a cart counts as abandoned and days until
 *    it counts as lost (10 at least, the plugin's own minimums).
 */
final class Cart_Abandonment_Operations
{
    public const READ_CAPABILITY = 'manage_woocommerce';

    public const WRITE_CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_cart_abandonment_write';

    private const MAX_PAGE_SIZE = 100;

    /** Plugin defaults (2.2.x get_plugin_options()) for the settings read. */
    private const DEFAULTS = [
        'wcf_ca_status'                               => 'on',
        'wcf_ca_cron_run_time'                        => 20,
        'wcf_ca_cart_lost_time'                       => 30,
        'wcf_ca_ignore_users'                         => [],
        'wcf_ca_excludes_orders'                      => [ 'processing', 'completed' ],
        'wcf_ca_coupon_code_status'                   => 'off',
        'wcf_ca_discount_type'                        => 'percent',
        'wcf_ca_coupon_amount'                        => 10,
        'wcf_ca_coupon_expiry'                        => 0,
        'wcf_ca_coupon_expiry_unit'                   => 'hours',
        'wcf_ca_auto_delete_coupons'                  => 'off',
        'wcf_ca_gdpr_status'                          => 'off',
        'wcf_ca_send_recovery_report_emails_to_admin' => 'on',
        'wcf_ca_zapier_tracking_status'               => 'off',
    ];

    /**
     * @param \Closure(): (true|array<string, mixed>) $presence The plugin and tables check every op declares.
     */
    public function __construct(
        private readonly Cart_Abandonment_Store $store,
        private readonly Cart_Abandonment_Snapshots $snapshots,
        private readonly \Closure $presence
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $date    = [ 'type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' ];
        $base    = [ 'capability' => self::READ_CAPABILITY, 'requires' => $this->presence ];
        $enabled = Typed::bool(apply_filters('wpmcp_enable_cart_abandonment_write', false));
        $write   = [
            'capability'         => self::WRITE_CAPABILITY,
            'requires'           => $this->presence,
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'cartabandonment-get-settings'    => $base + [
                'mode'         => 'read',
                'description'  => 'Cart Abandonment Recovery settings: tracking on or off, minutes until a cart counts as abandoned and days until lost, excluded roles and order statuses, sender, coupon, GDPR and webhook switches (the webhook URL is never returned)',
                'input_schema' => [ 'type' => 'object' ],
                'handler'      => fn (): array => [ 'settings' => $this->settings(), 'writes_enabled' => $enabled ],
            ],
            'cartabandonment-get-stats'       => $base + [
                'mode'         => 'read',
                'description'  => 'Cart Abandonment Recovery totals for a date range (start_date, end_date as YYYY-MM-DD, default the last 7 days): recoverable, recovered and lost carts with their revenue, and the recovery rate',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'start_date' => $date, 'end_date' => $date ] ],
                'validate'     => fn (array $args): ?array => $this->date_refusal($args),
                'handler'      => fn (array $args): array => $this->stats($args),
            ],
            'cartabandonment-list-carts'      => $base + [
                'mode'         => 'read',
                'description'  => 'List tracked carts newest first: id, status (abandoned, completed = recovered, lost, normal), total, items, date, unsubscribed, emails sent, customer email MASKED. status filters; page_size (default 20, max 100) and offset. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'    => [ 'type' => 'string', 'enum' => Cart_Abandonment_Store::STATUSES ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->carts($args),
            ],
            'cartabandonment-list-templates'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Cart Abandonment Recovery follow-up emails: id, name, subject, active, delay after abandonment (delay, delay_unit, delay_minutes), sent and queued counts',
                'input_schema' => [ 'type' => 'object' ],
                'handler'      => fn (): array => [ 'templates' => array_map(fn (array $row): array => $this->template_view($row, false), $this->store->templates()), 'writes_enabled' => $enabled ],
            ],
            'cartabandonment-get-template'    => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Cart Abandonment Recovery follow-up email in full: subject, HTML body, delay and its coupon settings',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => $id ], 'required' => [ 'template_id' ] ],
                'validate'     => fn (array $args): ?array => $this->template_refusal(Typed::int($args['template_id'] ?? null)),
                'handler'      => fn (array $args): array => [ 'template' => $this->template_view((array) $this->store->template(Typed::int($args['template_id'] ?? null)), true) ],
            ],
            'cartabandonment-update-template' => $write + [
                'description'  => 'Edit one Cart Abandonment Recovery follow-up email: name, subject, body (HTML), active, delay and delay_unit (MINUTE, HOUR, DAY). Emails already queued keep their send time. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_cart_abandonment_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'template_id' => $id,
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'subject'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
                        'body'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200000 ],
                        'active'      => [ 'type' => 'boolean' ],
                        'delay'       => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ],
                        'delay_unit'  => [ 'type' => 'string', 'enum' => array_keys(Cart_Abandonment_Store::UNITS) ],
                    ],
                    'required'   => [ 'template_id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->update_refusal($args),
                'snapshot'     => fn (array $args): ?array => $this->snapshots->template(Typed::int($args['template_id'] ?? null), self::columns($args)),
                'handler'      => fn (array $args): array => $this->update_template($args),
            ],
            'cartabandonment-update-timing'   => $write + [
                'description'  => 'Set when Cart Abandonment Recovery counts a cart as abandoned (abandoned_after_minutes, 10 at least) and as lost (lost_after_days, 10 at least). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_cart_abandonment_write filter)',
                'input_schema' => [
                    'type'          => 'object',
                    'minProperties' => 1,
                    'properties'    => [
                        'abandoned_after_minutes' => [ 'type' => 'integer', 'minimum' => 10, 'maximum' => 100000 ],
                        'lost_after_days'         => [ 'type' => 'integer', 'minimum' => 10, 'maximum' => 3650 ],
                    ],
                ],
                'snapshot'     => fn (): array => $this->snapshots->timing(),
                'handler'      => fn (array $args): array => $this->update_timing($args),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $opt = fn (string $name) => $this->store->option($name, self::DEFAULTS[ $name ] ?? '');
        return [
            'tracking_enabled'        => 'on' === $opt('wcf_ca_status'),
            'abandoned_after_minutes' => Typed::int($opt('wcf_ca_cron_run_time')),
            'lost_after_days'         => Typed::int($opt('wcf_ca_cart_lost_time')),
            'ignored_roles'           => Typed::strings($opt('wcf_ca_ignore_users')),
            'excluded_order_statuses' => Typed::strings($opt('wcf_ca_excludes_orders')),
            'from_name'               => Typed::str($opt('wcf_ca_from_name')),
            'from_email'              => Typed::str($opt('wcf_ca_from_email')),
            'reply_email'             => Typed::str($opt('wcf_ca_reply_email')),
            'coupon'                  => [
                'enabled'      => 'on' === $opt('wcf_ca_coupon_code_status'),
                'type'         => Typed::str($opt('wcf_ca_discount_type')),
                'amount'       => Typed::int($opt('wcf_ca_coupon_amount')),
                'expiry'       => Typed::int($opt('wcf_ca_coupon_expiry')),
                'expiry_unit'  => Typed::str($opt('wcf_ca_coupon_expiry_unit')),
                'auto_delete'  => 'on' === $opt('wcf_ca_auto_delete_coupons'),
            ],
            'gdpr_enabled'            => 'on' === $opt('wcf_ca_gdpr_status'),
            'weekly_report_to_admin'  => 'on' === $opt('wcf_ca_send_recovery_report_emails_to_admin'),
            'zapier_enabled'          => 'on' === $opt('wcf_ca_zapier_tracking_status'),
            'zapier_webhook_set'      => '' !== Typed::str(get_option('wcf_ca_zapier_cart_abandoned_webhook', '')),
        ];
    }

    /**
     * @param array<mixed> $args
     * @return array{0: string, 1: string}
     */
    private static function range(array $args): array
    {
        $to   = isset($args['end_date']) ? Typed::str($args['end_date']) : gmdate('Y-m-d');
        $from = isset($args['start_date']) ? Typed::str($args['start_date']) : gmdate('Y-m-d', (int) strtotime($to . ' -7 days'));
        return [ $from, $to ];
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string}|null
     */
    private function date_refusal(array $args): ?array
    {
        foreach ([ 'start_date', 'end_date' ] as $key) {
            if (! isset($args[ $key ])) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', Typed::str($args[ $key ] ?? null), new \DateTimeZone('UTC'));
            if (false === $date || $date->format('Y-m-d') !== Typed::str($args[ $key ] ?? null)) {
                return [ 'code' => 'bad_date', 'message' => sprintf('%s is not a date (YYYY-MM-DD).', $key) ];
            }
        }
        [ $from, $to ] = self::range($args);
        return $from > $to ? [ 'code' => 'bad_date', 'message' => 'start_date is after end_date.' ] : null;
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    private function stats(array $args): array
    {
        [ $from, $to ] = self::range($args);
        $recoverable   = $this->store->report('abandoned', $from, $to);
        $recovered     = $this->store->report('completed', $from, $to);
        $lost          = $this->store->report('lost', $from, $to);
        $tracked       = $recoverable['orders'] + $recovered['orders'];
        return [
            'period'        => [ 'start' => $from, 'end' => $to ],
            'recoverable'   => $recoverable,
            'recovered'     => $recovered,
            'lost'          => $lost,
            'recovery_rate' => $tracked > 0 ? round($recovered['orders'] / $tracked * 100, 2) : 0.0,
            'note'          => 'Dates are the cart\'s tracked time; revenue is the cart total in the store currency.',
        ];
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    private function carts(array $args): array
    {
        $limit = Typed::int($args['page_size'] ?? null, 20);
        $page  = $this->store->carts(isset($args['status']) ? Typed::str($args['status']) : null, $limit, Typed::int($args['offset'] ?? null));
        $carts = [];
        foreach ($page['rows'] as $row) {
            $contents = Cart_Abandonment_Store::plain(Typed::str($row['cart_contents'] ?? null));
            $items    = 0;
            foreach (is_array($contents) ? $contents : [] as $line) {
                $items += is_array($line) ? Typed::int($line['quantity'] ?? null, 1) : 0;
            }
            $carts[] = [
                'id'           => Typed::int($row['id'] ?? null),
                'status'       => Typed::str($row['order_status'] ?? null),
                'email'        => Masking::email(Typed::str($row['email'] ?? null)),
                'total'        => round(Typed::float($row['cart_total'] ?? null), 2),
                'items'        => $items,
                'date'         => Typed::str($row['time'] ?? null),
                'unsubscribed' => Typed::bool($row['unsubscribed'] ?? null),
                'emails_sent'  => $this->store->sent_for(Typed::str($row['session_id'] ?? null)),
            ];
        }
        return [ 'total' => $page['total'], 'carts' => $carts, 'masked' => true ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function template_view(array $row, bool $full): array
    {
        $id    = Typed::int($row['id'] ?? null);
        $unit  = Typed::str($row['frequency_unit'] ?? null);
        $delay = Typed::int($row['frequency'] ?? null);
        $view  = [
            'id'            => $id,
            'name'          => Typed::str($row['template_name'] ?? null),
            'subject'       => Typed::str($row['email_subject'] ?? null),
            'active'        => Typed::bool($row['is_activated'] ?? null),
            'delay'         => $delay,
            'delay_unit'    => $unit,
            'delay_minutes' => $delay * ( Cart_Abandonment_Store::UNITS[ $unit ] ?? 1 ),
        ] + $this->store->sends($id);
        if ($full) {
            $view['body'] = Typed::str($row['email_body'] ?? null);
            $view['meta'] = $this->store->template_meta($id);
        }
        return $view;
    }

    /** @return array{code: string, message: string, data: array<string, int>}|null */
    private function template_refusal(int $id): ?array
    {
        return null === $this->store->template($id)
            ? [ 'code' => 'template_not_found', 'message' => sprintf('%d is not a Cart Abandonment Recovery follow-up email.', $id), 'data' => [ 'template_id' => $id ] ]
            : null;
    }

    /**
     * The template columns an edit writes, sanitized: text fields as text,
     * the body as post HTML (no scripts or event handlers).
     *
     * @param array<mixed> $args
     * @return array<string, int|string>
     */
    private static function columns(array $args): array
    {
        $map = [
            'name'       => [ 'template_name', static fn (mixed $v): string => sanitize_text_field(Typed::str($v)) ],
            'subject'    => [ 'email_subject', static fn (mixed $v): string => sanitize_text_field(Typed::str($v)) ],
            'body'       => [ 'email_body', static fn (mixed $v): string => wp_kses_post(Typed::str($v)) ],
            'active'     => [ 'is_activated', static fn (mixed $v): int => true === $v ? 1 : 0 ],
            'delay'      => [ 'frequency', static fn (mixed $v): int => max(0, Typed::int($v)) ],
            'delay_unit' => [ 'frequency_unit', static fn (mixed $v): string => Typed::str($v) ],
        ];
        $set = [];
        foreach ($map as $arg => [ $column, $clean ]) {
            if (array_key_exists($arg, $args)) {
                $set[ $column ] = $clean($args[ $arg ]);
            }
        }
        return $set;
    }

    /**
     * @param array<mixed> $args
     * @return array{code: string, message: string, data?: array<string, int>}|null
     */
    private function update_refusal(array $args): ?array
    {
        $id      = Typed::int($args['template_id'] ?? null);
        $refusal = $this->template_refusal($id);
        if (null !== $refusal) {
            return $refusal;
        }
        $row = Typed::map($this->store->template($id));
        foreach (self::columns($args) as $column => $value) {
            if (Typed::str($row[ $column ] ?? null) !== (string) $value) {
                return null;
            }
        }
        return [ 'code' => 'no_change', 'message' => sprintf('Template %d already has those values; nothing to change.', $id) ];
    }

    /**
     * @param array<mixed> $args
     * @return array<string, mixed>
     */
    private function update_template(array $args): array
    {
        $id = Typed::int($args['template_id'] ?? null);
        $this->store->update_template($id, self::columns($args));
        return [
            'template' => $this->template_view(Typed::map($this->store->template($id)), false),
            'check'    => 'Emails already queued keep their send time; new abandoned carts use the new delay. Undo with rollback-operation.',
        ];
    }

    /**
     * @param array<mixed> $args
     * @return array<string, int>
     */
    private function update_timing(array $args): array
    {
        if (isset($args['abandoned_after_minutes'])) {
            update_option('wcf_ca_cron_run_time', Typed::int($args['abandoned_after_minutes']));
        }
        if (isset($args['lost_after_days'])) {
            update_option('wcf_ca_cart_lost_time', Typed::int($args['lost_after_days']));
        }
        return [
            'abandoned_after_minutes' => Typed::int($this->store->option('wcf_ca_cron_run_time', self::DEFAULTS['wcf_ca_cron_run_time'])),
            'lost_after_days'         => Typed::int($this->store->option('wcf_ca_cart_lost_time', self::DEFAULTS['wcf_ca_cart_lost_time'])),
        ];
    }
}
