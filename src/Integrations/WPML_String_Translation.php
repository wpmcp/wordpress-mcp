<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Tools\I18n\I18n_Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The string translation ops (list-string-domains, get-strings,
 * update-strings) over WPML String Translation (issue #1053), routed here by
 * I18n_Strings when WPML is the site's multilingual plugin. The answers have
 * the Polylang shape; a string's key is its icl_strings id.
 *
 * Writes go through WPML's own icl_add_string_translation() when WPML
 * String Translation is loaded, so its own hooks run; otherwise (and for a
 * clear, which WPML has no function for) through WPML_String_Store in the
 * same row format. The undo point is a plugin_table_rows snapshot of kind
 * wpml_strings over the named strings: their icl_strings rows and, as
 * companions, every icl_string_translations row of theirs, so a restore
 * puts back the translations and statuses exactly and removes rows the
 * write inserted.
 */
final class WPML_String_Translation
{
    /** Presence filter: WPML String Translation loaded (WPML_ST_VERSION). */
    public const ACTIVE_FILTER = 'wpmcp_wpml_st_active';

    private const MAX_PER_PAGE = 200;

    /** @var \Closure(int, string, string, int): mixed|null */
    private readonly ?\Closure $api;

    /** @var \Closure(): array<int, array{code: string, name: string, is_default: bool}> */
    private readonly \Closure $languages;

    public function __construct(
        private readonly WPML_String_Store $store,
        ?\Closure $api = null,
        ?\Closure $languages = null,
    ) {
        $this->api       = $api ?? (function_exists('icl_add_string_translation') ? static fn (int $id, string $lang, string $value, int $status): mixed => icl_add_string_translation($id, $lang, $value, $status) : null);
        $this->languages = $languages ?? static fn (): array => I18n_Adapter::list_languages();
    }

    public static function create(): self
    {
        global $wpdb;
        return new self(new WPML_String_Store($wpdb));
    }

    /** True, or the refusal naming what is missing. */
    public function requirement(): bool|array
    {
        if (! (bool) apply_filters('wpmcp_wpml_st_active', defined('WPML_ST_VERSION')) || ! $this->store->ready()) {
            return [
                'code'    => 'string_translation_unsupported',
                'message' => 'Theme and plugin string translation with WPML needs WPML String Translation. Install and activate it.',
                'data'    => [ 'plugin' => 'WPML String Translation', 'url' => admin_url('plugins.php') ],
            ];
        }
        return true;
    }

    /** The undo point of an update-strings call. */
    public static function snapshot_target(array $args): array
    {
        $ids = array_map(static fn ($s): int => (int) ((array) $s)['key'], (array) ($args['strings'] ?? []));
        return [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('wpml_strings', 'strings', $ids) ];
    }

    // ---------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------

    /** @return array<string, array{code: string, name: string, is_default: bool}> */
    private function language_map(): array
    {
        $out = [];
        foreach (($this->languages)() as $language) {
            $code = (string) ($language['code'] ?? '');
            if ('' !== $code) {
                $out[ $code ] = [ 'code' => $code, 'name' => (string) ($language['name'] ?? $code), 'is_default' => (bool) ($language['is_default'] ?? false) ];
            }
        }
        return $out;
    }

    private static function default_code(array $languages): string
    {
        foreach ($languages as $code => $language) {
            if ($language['is_default']) {
                return (string) $code;
            }
        }
        return '';
    }

    /** @return string[] the non-default language codes */
    private static function targets(array $languages): array
    {
        return array_values(array_map('strval', array_keys(array_filter($languages, static fn (array $l): bool => ! $l['is_default']))));
    }

    /** @return string[] the languages a string can be translated into */
    private static function string_targets(array $languages, string $source): array
    {
        return array_values(array_filter(array_map('strval', array_keys($languages)), static fn (string $code): bool => $code !== $source));
    }

    private function language_refusal(string $code): ?array
    {
        $languages = $this->language_map();
        if (isset($languages[ $code ])) {
            return null;
        }
        return [ 'code' => 'unknown_language', 'message' => sprintf('"%s" is not a language of this site.', $code), 'data' => [ 'languages' => array_keys($languages) ] ];
    }

    // ---------------------------------------------------------------
    // Handlers
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public function list_domains(): array
    {
        $languages = $this->language_map();
        $targets   = self::targets($languages);
        $done      = $this->store->complete_translations();

        $domains = [];
        foreach ($this->store->strings() as $row) {
            $domain = $row['domain'];
            if (! isset($domains[ $domain ])) {
                $domains[ $domain ] = [ 'domain' => $domain, 'strings' => 0, 'translated' => array_fill_keys($targets, 0), 'missing' => array_fill_keys($targets, 0) ];
            }
            ++$domains[ $domain ]['strings'];
            foreach ($targets as $code) {
                $key = isset($done[ $row['id'] ][ $code ]) ? 'translated' : 'missing';
                ++$domains[ $domain ][ $key ][ $code ];
            }
        }

        return [
            'plugin'           => 'wpml',
            'default_language' => self::default_code($languages),
            'languages'        => $targets,
            'domains'          => array_values($domains),
        ];
    }

    /** @return array<string, mixed> */
    public function get_strings(array $args): array
    {
        if (isset($args['language']) && null !== ($refusal = $this->language_refusal((string) $args['language']))) {
            throw new Operation_Refused($refusal['code'], $refusal['message'], $refusal['data']); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a JSON tool error surfaced by Integration_Dispatcher, never rendered as HTML.
        }
        $languages = $this->language_map();
        $done      = $this->store->complete_translations();
        $language  = isset($args['language']) ? (string) $args['language'] : null;
        $shown     = null === $language ? array_map('strval', array_keys($languages)) : [ $language ];
        $targets   = null === $language ? self::targets($languages) : [ $language ];
        $domain    = isset($args['domain']) ? (string) $args['domain'] : null;
        $search    = isset($args['search']) ? (string) $args['search'] : '';
        $missing   = true === ($args['missing_only'] ?? false);
        $page      = max(1, (int) ($args['page'] ?? 1));
        $per_page  = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 50)));

        $rows = [];
        foreach ($this->store->strings() as $row) {
            if (null !== $domain && $domain !== $row['domain']) {
                continue;
            }
            if ('' !== $search && false === stripos($row['value'], $search) && false === stripos($row['name'], $search)) {
                continue;
            }
            $translations = [];
            foreach ($shown as $code) {
                $translations[ $code ] = $code === $row['language'] ? $row['value'] : ($done[ $row['id'] ][ $code ] ?? null);
            }
            $absent = array_values(array_filter($targets, static fn (string $code): bool => $code !== $row['language'] && ! isset($done[ $row['id'] ][ $code ])));
            if ($missing && [] === $absent) {
                continue;
            }
            $rows[] = [
                'key'          => (string) $row['id'],
                'name'         => $row['name'],
                'domain'       => $row['domain'],
                'string'       => $row['value'],
                'language'     => $row['language'],
                'registered'   => true,
                'translations' => $translations,
                'missing'      => $absent,
            ];
        }

        return [
            'default_language' => self::default_code($languages),
            'total'            => count($rows),
            'page'             => $page,
            'per_page'         => $per_page,
            'pages'            => (int) ceil(count($rows) / $per_page),
            'strings'          => array_slice($rows, ($page - 1) * $per_page, $per_page),
        ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    public function update_refusal(array $args): ?array
    {
        $code    = (string) $args['language'];
        $refusal = $this->language_refusal($code);
        if (null !== $refusal) {
            return $refusal;
        }
        $keys     = array_map(static fn ($s): string => (string) ((array) $s)['key'], (array) $args['strings']);
        $repeated = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));
        if ([] !== $repeated) {
            return [ 'code' => 'duplicate_string', 'message' => sprintf('Each string may be given once: %s.', implode(', ', $repeated)), 'data' => [ 'duplicate' => $repeated ] ];
        }
        $strings = $this->store->strings();
        $unknown = array_values(array_filter($keys, static fn (string $key): bool => ! isset($strings[ (int) $key ]) || (string) (int) $key !== $key));
        if ([] !== $unknown) {
            return [ 'code' => 'unknown_string', 'message' => sprintf('Not a string of this site: %s. Read the keys with get-strings.', implode(', ', $unknown)), 'data' => [ 'unknown' => $unknown ] ];
        }
        $source = array_values(array_filter($keys, static fn (string $key): bool => $strings[ (int) $key ]['language'] === $code));
        if ([] !== $source) {
            return [ 'code' => 'source_language', 'message' => sprintf('These strings are written in %s; translate them into another language: %s.', $code, implode(', ', $source)), 'data' => [ 'keys' => $source ] ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function update_strings(array $args): array
    {
        $code      = (string) $args['language'];
        $strings   = $this->store->strings();
        $languages = $this->language_map();
        $written   = 0;
        $cleared   = 0;
        $out       = [];

        foreach ((array) $args['strings'] as $item) {
            $item        = (array) $item;
            $row         = $strings[ (int) $item['key'] ];
            $translation = trim((string) $item['translation']);
            if ('' === $translation) {
                if ($this->store->delete_translation($row['id'], $code)) {
                    ++$cleared;
                }
                $this->store->refresh_status($row['id'], self::string_targets($languages, $row['language']));
                $out[] = [ 'key' => (string) $row['id'], 'string' => $row['value'], 'translation' => null ];
                continue;
            }
            if (! current_user_can('unfiltered_html')) {
                $translation = wp_kses_post($translation);
            }
            if (null !== $this->api) {
                ($this->api)($row['id'], $code, $translation, WPML_String_Store::COMPLETE);
            } else {
                $this->store->save_translation($row['id'], $code, $translation, get_current_user_id());
                $this->store->refresh_status($row['id'], self::string_targets($languages, $row['language']));
            }
            ++$written;
            $out[] = [ 'key' => (string) $row['id'], 'string' => $row['value'], 'translation' => $translation ];
        }

        return [ 'language' => $code, 'written' => $written, 'cleared' => $cleared, 'strings' => $out ];
    }
}
