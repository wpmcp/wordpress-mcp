<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The PixelYourSite 11 modules pixelyoursite-set-settings writes (#1094):
 * each one's settings row, the key its IDs are listed under, and the shape
 * of a valid ID.
 */
enum PixelYourSite_Network: string
{
    case Facebook = 'facebook';
    case Ga       = 'ga';
    case Gtm      = 'gtm';

    public function row(): string
    {
        return match ($this) {
            self::Facebook => 'pys_facebook',
            self::Ga       => 'pys_ga',
            self::Gtm      => 'pys_gtm',
        };
    }

    public function id_key(): string
    {
        return match ($this) {
            self::Facebook => 'pixel_id',
            self::Ga       => 'tracking_id',
            self::Gtm      => 'gtm_id',
        };
    }

    public function accepts(string $id): bool
    {
        $pattern = match ($this) {
            self::Facebook => '/^\d{10,20}$/D',
            self::Ga       => '/^(G|AW|UA|GT|DC)-[A-Z0-9-]{3,30}$/D',
            self::Gtm      => '/^GTM-[A-Z0-9]{3,20}$/D',
        };
        return 1 === preg_match($pattern, $id);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $n): string => $n->value, self::cases());
    }
}
