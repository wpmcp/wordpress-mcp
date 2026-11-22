<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One product line a set-funnelkit-upsell-offer-products call asks for
 * (issue #1021). A field left out is null and keeps what the offer holds,
 * or FunnelKit's default for a product new to the offer.
 */
final class FunnelKit_Offer_Line
{
    /** The discount types Funnel Builder Pro's offer product editor offers. */
    public const DISCOUNT_TYPES = [ 'percentage_on_reg', 'percentage_on_sale', 'fixed_on_reg', 'fixed_on_sale' ];

    public function __construct(
        public readonly int $product_id,
        public readonly ?int $quantity,
        public readonly ?string $discount_type,
        public readonly ?float $discount_amount
    ) {
    }

    /** @param array<string,mixed> $line */
    public static function from_args(array $line): self
    {
        return new self(
            (int) ($line['product_id'] ?? 0),
            isset($line['quantity']) ? (int) $line['quantity'] : null,
            isset($line['discount_type']) ? (string) $line['discount_type'] : null,
            isset($line['discount_amount']) ? (float) $line['discount_amount'] : null
        );
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return self[]
     */
    public static function all(array $lines): array
    {
        return array_values(array_map([ self::class, 'from_args' ], array_filter($lines, 'is_array')));
    }
}
