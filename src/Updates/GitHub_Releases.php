<?php

namespace WPMCP\Updates;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update notices for every install of the self-hosted build, licensed or
 * not, from GitHub releases.
 *
 * wpmcp.php declares `Update URI: https://wpmcp-pro.com/`, so WordPress never
 * asks wordpress.org about this plugin. The licensing SDK never runs its own
 * updater for this build either: it starts FS_Plugin_Updater only for a
 * premium code base (Freemius\Bootstrap sets is_premium to false, since one
 * build serves free and licensed sites) or when a separate wpmcp-pro/ folder
 * is installed, and neither holds (issue #1180). Without this class no
 * install would ever hear of a new version. Since WordPress
 * 5.8, core asks the `update_plugins_{hostname}` filter about every plugin
 * with a custom Update URI, from inside its own update check, so this class
 * answers that filter and nothing else changes in how WordPress updates.
 *
 * Rules:
 *  - Self-hosted build only. src/Updates is removed from every wordpress.org
 *    style build (scripts/flavors/wporg/policy.php, shared by the WooCommerce
 *    flavor), whose updates come from the directory.
 *  - Licensed sites update from here too: the release's wpmcp-<version>.zip
 *    is the same full build the licensing SDK ships, and the licence lives
 *    in the database, so the update keeps it.
 *  - Full build only (Plugin::flavor() 'full'): any other flavor (the
 *    WooCommerce and Elementor builds) never takes wpmcp-<version>.zip, so
 *    no build can update itself into the full plugin.
 *  - The only requests are to api.github.com (the latest release, and the
 *    readme.txt at that release's tag for its WordPress and PHP requirements)
 *    and, when the site owner runs the update, the release zip on
 *    github.com. Plain GETs under a fixed user agent: no site address, no
 *    site data. readme.txt discloses this under External services.
 *  - Requests happen only inside WordPress's own update check (and the
 *    "View details" dialog), are capped at 5 seconds, and are cached in a
 *    site transient: 12 hours after a success, 1 hour after a failure, and
 *    refreshed after an hour when Dashboard > Updates is opened.
 *  - `wpmcp_github_updates_enabled` (bool) turns it off. It defaults to off
 *    for a git checkout, so an update never replaces a working copy.
 */
final class GitHub_Releases
{
    /** The host of the Update URI header in wpmcp.php (WPMCP_UPDATE_URI in scripts/lib/release-flavors.sh). */
    public const UPDATE_HOST = 'wpmcp-pro.com';

    public const REPO = 'wpmcp/wordpress-mcp';

    public const API_LATEST = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

    /** The plugin slug core uses for the "View details" dialog. */
    public const SLUG = 'wpmcp';

    /** Site transient: ['release' => array|null, 'checked' => int]. */
    public const CACHE_KEY = 'wpmcp_github_release';

    public const CACHE_OK = 12 * HOUR_IN_SECONDS;

    public const CACHE_FAIL = HOUR_IN_SECONDS;

    public const TIMEOUT = 5;

    /** Fixed user agent: WordPress's default one carries the site address. */
    public const USER_AGENT = 'WPMCP-Update-Check/1.0';

    /**
     * Up to 1.2.0, the release asset wpmcp-<version>.zip was the
     * wordpress.org directory cut (no licensing SDK, no Update URI). From
     * 1.2.1 it is this build. Never offer an older tag's asset of that name.
     */
    public const FIRST_SELF_HOSTED_ASSET = '1.2.1';

    /** On Dashboard > Updates a cached answer older than this is refreshed. */
    public const UPDATES_SCREEN_MAX_AGE = HOUR_IN_SECONDS;

    /** Release notes longer than this are cut before they are rendered. */
    private const MAX_NOTES = 20000;

    public static function register(): void
    {
        add_filter('update_plugins_' . self::UPDATE_HOST, [self::class, 'filter_update'], 10, 3);
        add_filter('plugins_api', [self::class, 'filter_plugins_api'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'keep_folder_name'], 10, 4);
        add_filter('http_request_args', [self::class, 'package_request_args'], 10, 2);
    }

    /**
     * The zip download goes through core's download_url(), whose default
     * user agent carries the site address. Requests for this repository's
     * release assets go out under the fixed user agent instead (redirects to
     * GitHub's download host keep it).
     *
     * @param array<string, mixed> $args
     * @param string               $url
     * @return array<string, mixed>
     */
    public static function package_request_args($args, $url)
    {
        if (is_array($args) && is_string($url) && str_starts_with($url, 'https://github.com/' . self::REPO . '/releases/download/')) {
            $args['user-agent'] = self::USER_AGENT;
        }

        return $args;
    }

    /** The basename of the running copy, for example wpmcp/wpmcp.php. */
    private static function basename(): string
    {
        return plugin_basename(WPMCP_FILE);
    }

    /**
     * True when this install should take its updates from GitHub: the full
     * build, and enabled. Licensed or not (issue #1180).
     */
    public static function active(): bool
    {
        if ('full' !== \WPMCP\Plugin::flavor()) {
            return false;
        }

        $enabled = ! file_exists(dirname(WPMCP_FILE) . '/.git');
        /**
         * Whether this install checks GitHub releases for updates.
         *
         * @param bool $enabled Default true, false for a git checkout.
         */
        return (bool) apply_filters('wpmcp_github_updates_enabled', $enabled);
    }

    /**
     * Core's `update_plugins_wpmcp-pro.com` filter, called from
     * wp_update_plugins() for each plugin with that Update URI host.
     *
     * @param array|false          $update      What an earlier callback answered.
     * @param array<string, mixed> $plugin_data The plugin's headers.
     * @param string               $plugin_file The plugin basename.
     * @return array|false
     */
    public static function filter_update($update, $plugin_data, $plugin_file)
    {
        if (self::basename() !== $plugin_file || ! empty($update) || ! self::active()) {
            return $update;
        }

        $release = self::release();
        if (null === $release) {
            return $update;
        }

        $installed = is_array($plugin_data) && isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : WPMCP_VERSION;
        if (! version_compare($release['version'], $installed, '>')) {
            return $update;
        }

        $offer = [
            'slug'        => self::SLUG,
            'version'     => $release['version'],
            'new_version' => $release['version'],
            'url'         => $release['url'],
            'package'     => $release['package'],
            'icons'       => [ 'svg' => plugins_url('assets/wpmcp-icon.svg', WPMCP_FILE) ],
        ];
        foreach ([ 'requires', 'tested', 'requires_php' ] as $field) {
            if ('' !== $release[ $field ]) {
                $offer[ $field ] = $release[ $field ];
            }
        }

        return $offer;
    }

    /**
     * "View details" for slug wpmcp: answers core's plugin information
     * dialog (plugin-install.php?tab=plugin-information) from the release,
     * so it never shows a wordpress.org entry that is not this build.
     * Anything else (other slugs, other callers of plugins_api(), an answer
     * another plugin already gave) passes through.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object|array|null  $args
     * @return false|object|array
     */
    public static function filter_plugins_api($result, $action, $args)
    {
        $slug = is_object($args) ? ($args->slug ?? '') : (is_array($args) ? ($args['slug'] ?? '') : '');
        // Only core's dialog, which plugin-install.php renders from inside
        // this action; other plugins_api() callers keep asking wordpress.org.
        $dialog = doing_action('install_plugins_pre_plugin-information');
        if (false !== $result || 'plugin_information' !== $action || self::SLUG !== $slug || ! $dialog) {
            return $result;
        }
        if (! self::active()) {
            return $result;
        }

        $release = self::release();
        if (null === $release) {
            return $result;
        }

        $notes = self::notes_html($release['notes']);
        $intro = sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            esc_html__('MCP server for WordPress with a snapshot before every write and one-click rollback.', 'wpmcp'),
            esc_url($release['url']),
            esc_html__('Release page on GitHub', 'wpmcp')
        );

        $info = [
            'name'          => 'WP MCP',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://wpmcp-pro.com/">Fahad Murtaza</a>',
            'homepage'      => 'https://wpmcp-pro.com/',
            'download_link' => $release['package'],
            'last_updated'  => $release['published'],
            'sections'      => [
                'description' => $intro,
                'changelog'   => '' !== $notes ? $notes : $intro,
            ],
        ];
        foreach ([ 'requires', 'tested', 'requires_php' ] as $field) {
            if ('' !== $release[ $field ]) {
                $info[ $field ] = $release[ $field ];
            }
        }

        return (object) $info;
    }

    /**
     * Keeps the folder of the running copy through an update.
     *
     * The release zip's root folder is wpmcp/. An install that lives in
     * another folder (a renamed upload, a GitHub source zip) would otherwise
     * be updated into wpmcp/, leaving WordPress with a missing active plugin.
     * Refuses a package that has no wpmcp.php at its top level.
     *
     * @param string|\WP_Error     $source
     * @param string               $remote_source
     * @param mixed                $upgrader
     * @param array<string, mixed> $hook_extra
     * @return string|\WP_Error
     */
    public static function keep_folder_name($source, $remote_source = '', $upgrader = null, $hook_extra = [])
    {
        if (! is_string($source) || ! is_array($hook_extra) || self::basename() !== ($hook_extra['plugin'] ?? null) || ! self::active()) {
            return $source;
        }

        global $wp_filesystem;
        if (! is_object($wp_filesystem)) {
            return $source;
        }

        $source = trailingslashit($source);
        if (! $wp_filesystem->exists($source . basename(WPMCP_FILE))) {
            return new \WP_Error(
                'wpmcp_update_package',
                __('The update package does not contain WP MCP: wpmcp.php is not at the top of its folder.', 'wpmcp')
            );
        }

        $folder = basename(dirname(WPMCP_FILE));
        if (basename(untrailingslashit($source)) === $folder) {
            return $source;
        }

        $target = trailingslashit(dirname(untrailingslashit($source))) . $folder . '/';
        if ($wp_filesystem->exists($target) || ! $wp_filesystem->move(untrailingslashit($source), untrailingslashit($target))) {
            return new \WP_Error(
                'wpmcp_update_folder',
                /* translators: %s: plugin folder name */
                sprintf(__('Could not keep the plugin folder name %s during the update.', 'wpmcp'), $folder)
            );
        }

        return $target;
    }

    /**
     * The latest usable release, from the cache or GitHub.
     *
     * @return array{version: string, url: string, package: string, notes: string, published: string, requires: string, tested: string, requires_php: string}|null
     */
    public static function release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('release', $cached) && ! self::force_check($cached)) {
            return is_array($cached['release']) ? $cached['release'] : null;
        }

        $release = self::fetch();
        set_site_transient(
            self::CACHE_KEY,
            [ 'release' => $release, 'checked' => time() ],
            null === $release ? self::CACHE_FAIL : self::CACHE_OK
        );

        return $release;
    }

    public static function flush(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * Dashboard > Updates runs core's update check on every visit and lets it
     * refetch after a minute; this cache follows it there after an hour, so
     * "Check again" finds a new release without the 12-hour wait and without
     * spending GitHub's unauthenticated rate limit (60 an hour per address).
     *
     * @param array<string, mixed> $cached
     */
    private static function force_check(array $cached): bool
    {
        if (! doing_action('load-update-core.php')) {
            return false;
        }

        return time() - (int) ($cached['checked'] ?? 0) >= self::UPDATES_SCREEN_MAX_AGE;
    }

    /** @return array<string, mixed> */
    private static function request_args(string $accept): array
    {
        return [
            'timeout'     => self::TIMEOUT,
            'redirection' => 2,
            'user-agent'  => self::USER_AGENT,
            'headers'     => [
                'Accept'               => $accept,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ];
    }

    /**
     * One GET of releases/latest. Null on any failure: transport error,
     * non-200, malformed JSON, a draft or prerelease, a tag that is not a
     * plain version, or a release without the wpmcp-<version>.zip asset.
     *
     * @return array{version: string, url: string, package: string, notes: string, published: string, requires: string, tested: string, requires_php: string}|null
     */
    private static function fetch(): ?array
    {
        $response = wp_safe_remote_get(self::API_LATEST, self::request_args('application/vnd.github+json'));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data) || ! empty($data['draft']) || ! empty($data['prerelease'])) {
            return null;
        }

        $tag = is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
        if (1 !== preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) {
            return null;
        }
        $version = $m[1];
        if (version_compare($version, self::FIRST_SELF_HOSTED_ASSET, '<')) {
            return null;
        }

        $asset = 'wpmcp-' . $version . '.zip';
        $names = [];
        foreach ((array) ($data['assets'] ?? []) as $item) {
            if (is_array($item) && is_string($item['name'] ?? null)) {
                $names[] = $item['name'];
            }
        }
        if (! in_array($asset, $names, true)) {
            return null;
        }

        $base    = 'https://github.com/' . self::REPO . '/releases';
        $url     = is_string($data['html_url'] ?? null) && str_starts_with($data['html_url'], $base . '/') ? $data['html_url'] : $base . '/tag/' . $tag;
        $notes   = is_string($data['body'] ?? null) ? substr($data['body'], 0, self::MAX_NOTES) : '';
        $publish = is_string($data['published_at'] ?? null) ? $data['published_at'] : '';

        $release = [
            'version'      => $version,
            'url'          => $url,
            'package'      => $base . '/download/' . $tag . '/' . $asset,
            'notes'        => $notes,
            'published'    => '' !== $publish ? gmdate('Y-m-d H:i:s', (int) strtotime($publish)) . ' GMT' : '',
            'requires'     => '',
            'tested'       => '',
            'requires_php' => '',
        ];

        // Requirements only matter for a version this site does not run yet.
        if (version_compare($version, WPMCP_VERSION, '>')) {
            $release = array_merge($release, self::requirements($tag));
        }

        return $release;
    }

    /**
     * Requires at least, Tested up to and Requires PHP from readme.txt at the
     * release tag, which is the readme the release zip ships. Empty strings
     * when it cannot be read: the update is still offered, core then simply
     * shows the compatibility as unknown.
     *
     * @return array{requires: string, tested: string, requires_php: string}
     */
    private static function requirements(string $tag): array
    {
        $out = [ 'requires' => '', 'tested' => '', 'requires_php' => '' ];
        $url = 'https://api.github.com/repos/' . self::REPO . '/contents/readme.txt?ref=' . rawurlencode($tag);

        $response = wp_safe_remote_get($url, self::request_args('application/vnd.github.raw+json'));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return $out;
        }

        // The raw media type returns the file itself; anything else (a JSON
        // envelope from a proxy that dropped it) simply matches nothing below.
        $head = substr((string) wp_remote_retrieve_body($response), 0, 4096);

        $map = [ 'requires' => 'Requires at least', 'tested' => 'Tested up to', 'requires_php' => 'Requires PHP' ];
        foreach ($map as $key => $label) {
            if (1 === preg_match('/^' . preg_quote($label, '/') . ':\s*(\d+(?:\.\d+){0,2})\s*$/mi', $head, $m)) {
                $out[ $key ] = $m[1];
            }
        }

        return $out;
    }

    /**
     * Release notes (GitHub Markdown) as the small HTML subset core's
     * plugin information dialog allows: headings, bullet lists, paragraphs,
     * inline code, bold and https links. Everything is escaped first.
     */
    public static function notes_html(string $markdown): string
    {
        $html    = '';
        $in_list = false;
        $para    = [];

        $flush_para = static function () use (&$html, &$para): void {
            if ([] !== $para) {
                $html .= '<p>' . implode(' ', $para) . '</p>';
                $para  = [];
            }
        };

        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $raw) {
            $line = trim($raw);
            if (1 === preg_match('/^[-*+]\s+(.*)$/', $line, $m)) {
                $flush_para();
                if (! $in_list) {
                    $html   .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if ($in_list) {
                $html   .= '</ul>';
                $in_list = false;
            }
            if ('' === $line) {
                $flush_para();
                continue;
            }
            if (1 === preg_match('/^#{1,6}\s+(.*)$/', $line, $m)) {
                $flush_para();
                $html .= '<h4>' . self::inline($m[1]) . '</h4>';
                continue;
            }
            $para[] = self::inline($line);
        }
        if ($in_list) {
            $html .= '</ul>';
        }
        $flush_para();

        return $html;
    }

    private static function inline(string $text): string
    {
        $text = esc_html($text);
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/',
            static fn (array $m): string => '<a href="' . esc_url(html_entity_decode($m[2])) . '">' . $m[1] . '</a>',
            $text
        );
    }
}
