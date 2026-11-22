<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Yoast SEO Premium redirects as ops on the theme pair (issue #681), paid
 * tier: yoast-list-redirects (read) and yoast-create-redirect,
 * yoast-update-redirect and yoast-delete-redirect. A redirect is named by
 * its origin and format (plain or regex), as in Yoast's own manager. Every
 * write is one option_set snapshot of the three options Yoast_Redirect_Store
 * writes, so rollback-operation puts the list and its exports back exactly.
 * Writes change live routing, so they share Rank Math's opt-in
 * (wpmcp_enable_seo_redirect_write).
 */
final class Yoast_Premium_Redirect_Ops
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    private const TYPES     = [ 301, 302, 307, 410, 451 ];
    private const NO_TARGET = [ 410, 451 ];
    private const MAX_PAGE  = 100;

    public function __construct(private readonly Yoast_Redirect_Store $store)
    {
    }

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_yoast_premium_active', defined('WPSEO_PREMIUM_VERSION') || class_exists('WPSEO_Premium'));
    }

    /** @return true|array{code: string, message: string} */
    private static function requirement(): bool|array
    {
        return self::active() ? true : Plugin_Refusal::make('yoast_premium_inactive', 'Yoast SEO Premium', 'wordpress-seo-premium/wp-seo-premium.php');
    }

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $enabled  = (bool) apply_filters('wpmcp_enable_seo_redirect_write', false);
        $key      = [
            'origin' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ],
            'format' => [ 'type' => 'string', 'enum' => [ 'plain', 'regex' ] ],
        ];
        $fields   = [
            'url'  => [ 'type' => 'string', 'maxLength' => 2000 ],
            'type' => [ 'type' => 'integer', 'enum' => self::TYPES ],
        ];
        $snapshot = static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(Yoast_Redirect_Store::options()) ];
        $base     = [ 'tier' => 'pro', 'capability' => 'manage_options', 'requires' => static fn () => self::requirement() ];
        $write    = $base + [ 'mode' => 'write', 'enabled_by_default' => $enabled, 'opt_in_filter' => 'wpmcp_enable_seo_redirect_write', 'snapshot' => $snapshot ];

        return [
            'yoast-list-redirects'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Yoast SEO Premium redirects {origin, url, type, format}. Filter by format (plain or regex) or a search over origin and url; paged',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'format'   => $key['format'],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->list($args),
            ],
            'yoast-create-redirect' => $write + [
                'description'  => 'Create a Yoast SEO Premium redirect from origin (a path, or a regex with format regex) to url; type 301 (default), 302, 307, or 410 and 451 with no url. Duplicates and loops are refused. Undo: rollback-operation. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => [ 'type' => 'object', 'properties' => $key + $fields, 'required' => [ 'origin' ] ],
                'validate'     => fn (array $args): ?array => $this->refusal(fn () => $this->plan_create($args)),
                'handler'      => fn (array $args): array => $this->create($args),
            ],
            'yoast-update-redirect' => $write + [
                'description'  => 'Change the url or type of the Yoast SEO Premium redirect named by origin and format (default plain). Only passed fields change. Undo: rollback-operation. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => [ 'type' => 'object', 'properties' => $key + $fields, 'required' => [ 'origin' ] ],
                'validate'     => fn (array $args): ?array => $this->refusal(fn () => $this->plan_update($args)),
                'handler'      => fn (array $args): array => $this->update($args),
            ],
            'yoast-delete-redirect' => $write + [
                'mode'         => 'destructive',
                'description'  => 'Delete the Yoast SEO Premium redirect named by origin and format (default plain). Undo: rollback-operation brings it back. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => [ 'type' => 'object', 'properties' => $key, 'required' => [ 'origin' ] ],
                'validate'     => fn (array $args): ?array => $this->refusal(fn () => $this->must_find($args)),
                'handler'      => fn (array $args): array => $this->delete($args),
            ],
        ];
    }

    /** A plain origin is stored without its leading and trailing slashes, as Yoast's WPSEO_Redirect does. */
    private static function origin(array $args): string
    {
        $origin = trim((string) $args['origin']);
        return 'regex' === self::format($args) ? $origin : trim($origin, '/');
    }

    private static function format(array $args): string
    {
        return 'regex' === ($args['format'] ?? 'plain') ? 'regex' : 'plain';
    }

    private function refusal(callable $plan): ?array
    {
        if ($this->store->file_mode()) {
            return [ 'code' => 'redirect_file_mode', 'message' => 'Yoast SEO Premium writes its redirects to an .htaccess or nginx file on this site; change them in Yoast so the server file is rewritten.', 'data' => [] ];
        }
        try {
            $plan();
            return null;
        } catch (Operation_Refused $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
    }

    private function must_find(array $args): int
    {
        $index = $this->store->find($this->store->all(), self::origin($args), self::format($args));
        if (null === $index) {
            throw new Operation_Refused('redirect_not_found', sprintf('No Yoast SEO Premium redirect of format "%s" has the origin "%s".', self::format($args), self::origin($args)));
        }
        return $index;
    }

    /** @return array{origin: string, url: string, type: int, format: string} */
    private function checked(string $origin, string $format, string $url, int $type): array
    {
        if ('' === $origin) {
            throw new Operation_Refused('invalid_argument', 'origin may not be empty.');
        }
        if ('regex' === $format && false === @preg_match('`' . str_replace('`', '\\`', $origin) . '`', '')) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing whether the pattern compiles.
            throw new Operation_Refused('invalid_regex', 'origin is not a valid regular expression.');
        }
        if (in_array($type, self::NO_TARGET, true)) {
            $url = '';
        } elseif ('' === $url) {
            throw new Operation_Refused('invalid_argument', sprintf('A %d redirect needs a url.', $type));
        }
        if ('plain' === $format && '' !== $url && trim((string) wp_parse_url($url, PHP_URL_PATH), '/') === $origin && null === wp_parse_url($url, PHP_URL_HOST)) {
            throw new Operation_Refused('redirect_loop', 'The url points back at the origin.');
        }
        return [ 'origin' => $origin, 'url' => $url, 'type' => $type, 'format' => $format ];
    }

    private function plan_create(array $args): array
    {
        $origin = self::origin($args);
        $format = self::format($args);
        if (null !== $this->store->find($this->store->all(), $origin, $format)) {
            throw new Operation_Refused('redirect_exists', sprintf('A redirect of format "%s" with the origin "%s" already exists; use yoast-update-redirect.', $format, $origin));
        }
        return $this->checked($origin, $format, trim((string) ($args['url'] ?? '')), (int) ($args['type'] ?? 301));
    }

    private function plan_update(array $args): array
    {
        $current = $this->store->all()[ $this->must_find($args) ];
        return $this->checked($current['origin'], $current['format'], array_key_exists('url', $args) ? trim((string) $args['url']) : $current['url'], (int) ($args['type'] ?? $current['type']));
    }

    private function list(array $args): array
    {
        $rows   = $this->store->all();
        $format = $args['format'] ?? null;
        $search = trim((string) ($args['search'] ?? ''));
        $rows   = array_values(array_filter($rows, static fn (array $r): bool => (null === $format || $r['format'] === $format)
            && ('' === $search || false !== stripos($r['origin'], $search) || false !== stripos($r['url'], $search))));
        $per    = (int) ($args['per_page'] ?? 50);
        $page   = (int) ($args['page'] ?? 1);
        return [ 'total' => count($rows), 'page' => $page, 'per_page' => $per, 'redirects' => array_slice($rows, ($page - 1) * $per, $per) ];
    }

    private function create(array $args): array
    {
        $redirect = $this->plan_create($args);
        $all      = $this->store->all();
        $all[]    = $redirect;
        $this->store->save($all);
        return [ 'redirect' => $redirect ];
    }

    private function update(array $args): array
    {
        $index         = $this->must_find($args);
        $redirect      = $this->plan_update($args);
        $all           = $this->store->all();
        $all[ $index ] = $redirect;
        $this->store->save($all);
        return [ 'redirect' => $redirect ];
    }

    private function delete(array $args): array
    {
        $index   = $this->must_find($args);
        $all     = $this->store->all();
        $removed = $all[ $index ];
        unset($all[ $index ]);
        $this->store->save($all);
        return [ 'deleted' => $removed ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
