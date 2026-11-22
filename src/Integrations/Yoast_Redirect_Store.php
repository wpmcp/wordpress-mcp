<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Yoast SEO Premium's redirect storage (issue #681): the redirect manager
 * keeps every redirect in the wpseo-premium-redirects-base option, a list of
 * {origin, url, type, format}, and exports them per format into
 * wpseo-premium-redirects-export-plain and -export-regex (origin => {url,
 * type}), which its PHP redirect handler reads on every request. save()
 * writes all three the way WPSEO_Redirect_Option::save() and its option
 * exporter do, so a site served by PHP redirects sees the change at once.
 *
 * When Yoast is set to write redirects to an .htaccess or nginx file
 * (wpseo_redirect: disable_php_redirect on), the file is not ours to undo,
 * so the ops refuse writes (file_mode()).
 */
final class Yoast_Redirect_Store
{
    public const BASE  = 'wpseo-premium-redirects-base';
    public const PLAIN = 'wpseo-premium-redirects-export-plain';
    public const REGEX = 'wpseo-premium-redirects-export-regex';

    /** @return string[] every option save() writes, for the undo point. */
    public static function options(): array
    {
        return [ self::BASE, self::PLAIN, self::REGEX ];
    }

    /** @return array<int, array{origin: string, url: string, type: int, format: string}> */
    public function all(): array
    {
        $out = [];
        foreach ((array) get_option(self::BASE, []) as $row) {
            if (! is_array($row) || ! isset($row['origin'])) {
                continue;
            }
            $out[] = [
                'origin' => (string) $row['origin'],
                'url'    => (string) ($row['url'] ?? ''),
                'type'   => (int) ($row['type'] ?? 301),
                'format' => 'regex' === ($row['format'] ?? 'plain') ? 'regex' : 'plain',
            ];
        }
        return $out;
    }

    /** The index of the redirect with this origin and format, or null. */
    public function find(array $all, string $origin, string $format): ?int
    {
        foreach ($all as $i => $row) {
            if ($row['origin'] === $origin && $row['format'] === $format) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int, array{origin: string, url: string, type: int, format: string}> $all */
    public function save(array $all): void
    {
        $all     = array_values($all);
        $exports = [ 'plain' => [], 'regex' => [] ];
        foreach ($all as $row) {
            $exports[ $row['format'] ][ $row['origin'] ] = [ 'url' => $row['url'], 'type' => $row['type'] ];
        }
        update_option(self::BASE, $all, false);
        update_option(self::PLAIN, $exports['plain'], false);
        update_option(self::REGEX, $exports['regex'], false);
    }

    public function file_mode(): bool
    {
        $settings = (array) get_option('wpseo_redirect', []);
        return 'on' === ($settings['disable_php_redirect'] ?? 'off');
    }
}
