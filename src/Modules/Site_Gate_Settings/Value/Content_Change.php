<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Value;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The gate page content a set-site-gate-content call asks for (issue #1099).
 * A field left out of the call is null and stays as stored. The countdown is
 * a Unix time; countdown_off says the call turns the countdown off.
 */
final class Content_Change
{
    public function __construct(
        public readonly ?string $headline = null,
        public readonly ?string $message = null,
        public readonly ?int $logo = null,
        public readonly ?int $countdown = null,
        public readonly bool $countdown_off = false,
    ) {
    }

    /** @param array<mixed> $args already checked against the op's schema. */
    public static function from_args(array $args): self|Refusal
    {
        $countdown = null;
        if (array_key_exists('countdown', $args) && null !== $args['countdown']) {
            $time = strtotime(Stored::text($args['countdown']));
            if (false === $time) {
                return new Refusal('invalid_countdown', 'countdown must be an ISO 8601 date and time, such as 2030-01-31T18:00:00Z.');
            }
            if ($time <= time()) {
                return new Refusal('countdown_in_past', 'countdown must be in the future; pass null to turn the countdown off.');
            }
            $countdown = $time;
        }

        return new self(
            isset($args['headline']) ? Stored::text($args['headline']) : null,
            isset($args['message']) ? Stored::text($args['message']) : null,
            isset($args['logo']) ? Stored::int($args['logo']) : null,
            $countdown,
            array_key_exists('countdown', $args) && null === $args['countdown'],
        );
    }

    /** @return list<string> the fields the call names. */
    public function fields(): array
    {
        $fields = [];
        foreach (['headline' => $this->headline, 'message' => $this->message, 'logo' => $this->logo] as $field => $value) {
            if (null !== $value) {
                $fields[] = $field;
            }
        }
        if (null !== $this->countdown || $this->countdown_off) {
            $fields[] = 'countdown';
        }
        return $fields;
    }
}
