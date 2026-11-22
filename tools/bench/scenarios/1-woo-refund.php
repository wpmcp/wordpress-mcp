<?php

declare(strict_types=1);

namespace WPMCP\Bench\Scenarios;

use WPMCP\Bench\Data;
use WPMCP\Bench\Scenario;

/**
 * Scenario 1: "Find the 5 most recent orders of the customer with email X
 * and refund the newest one" (WooCommerce).
 *
 * Fixtures: one customer with 7 orders on different days, 5 orders of other
 * customers. Refunds are off by default (wpmcp_woo_op_enabled), so the seed
 * turns refunds.create on the way a site owner would.
 *
 * Ideal sequence on full: the op names are not in tools/list, so one
 * woo-ops call to learn them, then customers.list by email, orders.list for
 * that customer (newest first, 5), and refunds.create on the newest with
 * confirm:true (the description says refunds need it).
 */
final class Woo_Refund implements Scenario
{
    private const EMAIL       = 'bench.buyer@example.test';
    private const OTHER_EMAIL = 'bench.other@example.test';

    public function id(): int
    {
        return 1;
    }

    public function slug(): string
    {
        return 'woo-refund';
    }

    public function title(): string
    {
        return 'Find the 5 most recent orders of a customer by email and refund the newest';
    }

    /** @return list<string> */
    public function missing(): array
    {
        return class_exists('WooCommerce') && function_exists('wc_create_order') ? [] : ['WooCommerce'];
    }

    /** @return array<string, mixed> */
    public function seed(): array
    {
        add_filter('wpmcp_woo_op_enabled', static fn(mixed $enabled, mixed $op): mixed => 'refunds.create' === $op ? true : $enabled, 10, 2);

        $product = new \WC_Product_Simple();
        $product->set_name('Bench Mug');
        $product->set_regular_price('12.00');
        $product->set_status('publish');
        $product->save();

        $customer = $this->customer('bench_buyer', self::EMAIL);
        $other    = $this->customer('bench_other', self::OTHER_EMAIL);

        $mine = [];
        for ($i = 7; $i >= 1; $i--) {
            $mine[ $i ] = $this->order($product, $customer, self::EMAIL, $i * 3, $i);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->order($product, $other, self::OTHER_EMAIL, $i, 1);
        }

        return ['email' => self::EMAIL, 'newest_order' => $mine[1]];
    }

    /**
     * @param array<string, mixed> $fixtures
     * @return array<int, array<string, mixed>>
     */
    public function steps(array $fixtures): array
    {
        return [
            ['tool' => 'wpmcp/woo-ops', 'args' => [], 'as' => 'ops'],
            ['tool' => 'wpmcp/woo-read', 'args' => ['op' => 'customers.list', 'params' => ['email' => Data::str($fixtures, 'email')]], 'as' => 'customers'],
            [
                'tool' => 'wpmcp/woo-read',
                'args' => static function (array $r): array {
                    $id = Data::int($r, 'customers', 'body', 0, 'id');
                    if (0 === $id) {
                        throw new \RuntimeException('customers.list found no customer with that email');
                    }
                    return ['op' => 'orders.list', 'params' => ['customer' => $id, 'per_page' => 5, 'orderby' => 'date', 'order' => 'desc']];
                },
                'as'   => 'orders',
            ],
            [
                'tool' => 'wpmcp/woo-write',
                'args' => static function (array $r): array {
                    $newest = Data::arr($r, 'orders', 'body', 0);
                    if ([] === $newest) {
                        throw new \RuntimeException('orders.list returned no orders');
                    }
                    return [
                        'op'      => 'refunds.create',
                        'params'  => ['order_id' => Data::int($newest, 'id'), 'amount' => Data::str($newest, 'total'), 'reason' => 'Bench refund'],
                        'confirm' => true,
                    ];
                },
                'as'   => 'refund',
            ],
        ];
    }

    /** @return list<string> */
    public function searches(): array
    {
        return ['woo'];
    }

    /**
     * @param array<string, mixed> $fixtures
     * @param array<string, mixed> $state
     */
    public function verify(array $fixtures, array $state): ?string
    {
        $orders = Data::rows($state, 'orders', 'body');
        if (5 !== count($orders)) {
            return sprintf('expected 5 orders, got %d', count($orders));
        }
        $newest = Data::int($fixtures, 'newest_order');
        if (Data::int($orders, 0, 'id') !== $newest) {
            return 'the first order listed is not the newest';
        }
        $order = wc_get_order($newest);
        return $order instanceof \WC_Order && 1 === count($order->get_refunds()) ? null : 'the newest order has no refund';
    }

    private function customer(string $login, string $email): int
    {
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(24),
            'role'       => 'customer',
        ]);
        if (! is_int($id)) {
            throw new \RuntimeException('could not create the customer ' . $login . ': ' . $id->get_error_message());
        }
        return $id;
    }

    private function order(\WC_Product $product, int $user, string $email, int $days_ago, int $qty): int
    {
        $order = wc_create_order(['customer_id' => $user]);
        if (! $order instanceof \WC_Order) {
            throw new \RuntimeException('wc_create_order failed');
        }
        $order->add_product($product, $qty);
        $order->set_billing_email($email);
        $order->set_billing_first_name('Bench');
        $order->set_billing_last_name('Buyer');
        $order->calculate_totals();
        $order->set_status('processing');
        $order->set_date_created(time() - $days_ago * DAY_IN_SECONDS);
        $order->save();
        return $order->get_id();
    }
}
