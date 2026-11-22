<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One hustle-set-schedule call (#1091): whether the schedule is on, and its
 * start and end as site-local "YYYY-MM-DD HH:MM" (null: no start or end),
 * written in the shape Hustle's settings keep (m/d/Y dates, 12-hour hour,
 * minute and am or pm).
 */
final class Hustle_Schedule
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?\DateTimeImmutable $start,
        public readonly ?\DateTimeImmutable $end
    ) {
    }

    /** @param array<mixed> $args the op's arguments, keyed by name */
    public static function from_args(array $args): self
    {
        return new self(true === ($args['enabled'] ?? null), self::time($args['start'] ?? null), self::time($args['end'] ?? null));
    }

    public static function time(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
        return false === $time || $time->format('Y-m-d H:i') !== $value ? null : $time;
    }

    /**
     * The settings with this schedule in them; every other setting stays.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function apply(array $settings): array
    {
        $schedule                = Typed::map($settings['schedule'] ?? null);
        $settings['is_schedule'] = $this->enabled ? '1' : '0';
        foreach ([ 'start' => $this->start, 'end' => $this->end ] as $side => $time) {
            $schedule[ 'not_schedule_' . $side ] = null === $time ? '1' : '0';
            if (null === $time) {
                continue;
            }
            $schedule[ $side . '_date' ]            = $time->format('m/d/Y');
            $schedule[ $side . '_hour' ]            = $time->format('h');
            $schedule[ $side . '_minute' ]          = $time->format('i');
            $schedule[ $side . '_meridiem_offset' ] = $time->format('a');
        }
        $settings['schedule'] = $schedule;
        return $settings;
    }
}
