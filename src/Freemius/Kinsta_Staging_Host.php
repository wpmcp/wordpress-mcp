<?php

declare(strict_types=1);

namespace WPMCP\Freemius;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kinsta's own staging domains, which Staging_Detector's general rule
 * refuses because a word follows the hyphen (#807).
 *
 * Kinsta documents these staging addresses
 * (https://kinsta.com/docs/wordpress-hosting/staging-environment/):
 *
 *   stg-<site>-<environment>.kinsta.cloud      current format
 *   staging-<site>-<environment>.kinsta.cloud  older environments
 *   staging-<site>.kinsta.cloud                older environments
 *   staging-<site>.kinsta.com                  older environments
 *
 * Only that shape counts: exactly one label under kinsta.cloud or
 * kinsta.com, starting with the platform's staging prefix and followed by
 * hyphen-separated words. A live site's temporary domain is
 * <site>.kinsta.cloud, named after the site, so the stg- form must carry
 * both the site and the environment part: stg-shop.kinsta.cloud could be
 * a live site called "STG Shop". Premium environments
 * (env-<site>-<environment>.kinsta.cloud) are left out, because "env" is
 * too ordinary a start of a site name to tell them from a live site, and a
 * false positive would let a production clone keep Pro without a licence
 * seat. What remains is a live site whose own name starts with "staging"
 * (staging-pros.kinsta.cloud), and only while it still runs on its
 * temporary domain: the cost of covering Kinsta's older environments.
 *
 * Pure on purpose, like Staging_Detector: no state, no options, no hooks.
 */
final class Kinsta_Staging_Host
{
    /** Platform domain => pattern for the one label in front of it. */
    private const LABELS = [
        'kinsta.cloud' => '/^(?:staging(?:-[a-z0-9]+)+|stg(?:-[a-z0-9]+){2,})$/',
        'kinsta.com'   => '/^staging(?:-[a-z0-9]+)+$/',
    ];

    /**
     * True when $host is a Kinsta staging environment's own domain.
     *
     * @param string $host A lowercase host name without a port.
     */
    public static function matches(string $host): bool
    {
        $labels = explode('.', $host);
        if (3 !== count($labels)) {
            return false;
        }

        $pattern = self::LABELS[$labels[1] . '.' . $labels[2]] ?? null;
        if (null === $pattern) {
            return false;
        }

        return 1 === preg_match($pattern, $labels[0]);
    }
}
