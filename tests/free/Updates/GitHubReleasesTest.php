<?php

namespace WPMCP\Tests\Free\Updates;

use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Updates\GitHub_Releases;

/**
 * Update notices for free installs of the self-hosted build, answered from
 * the latest GitHub release through core's update_plugins_{hostname} filter.
 * HTTP is faked with pre_http_request; every outbound URL is recorded.
 */
class GitHubReleasesTest extends \WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    /** @var array|\WP_Error The canned releases/latest response. */
    private $latest;

    /** @var array|\WP_Error The canned readme.txt response. */
    private $readme;

    protected function setUp(): void
    {
        parent::setUp();
        GitHub_Releases::flush();
        Gate::set_pro_for_tests(false);
        add_filter('wpmcp_github_updates_enabled', '__return_true');
        $this->requests = [];
        $this->latest   = $this->json(200, $this->release('v9.0.0'));
        $this->readme   = [
            'headers'  => [],
            'body'     => "=== WPMCP ===\nRequires at least: 6.9\nTested up to: 7.1\nRequires PHP: 8.1\nStable tag: 9.0.0\n",
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
        ];
        add_filter('pre_http_request', [ $this, 'fake_http' ], 10, 3);
        add_action('set_site_transient_' . GitHub_Releases::CACHE_KEY, [ $this, 'record_ttl' ], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'fake_http' ], 10);
        remove_action('set_site_transient_' . GitHub_Releases::CACHE_KEY, [ $this, 'record_ttl' ], 10);
        remove_filter('wpmcp_github_updates_enabled', '__return_true');
        remove_filter('wpmcp_github_updates_enabled', '__return_false');
        Gate::set_pro_for_tests(null);
        GitHub_Releases::flush();
        parent::tearDown();
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requests[] = [ 'url' => $url, 'args' => $args ];
        if (str_starts_with($url, GitHub_Releases::API_LATEST)) {
            return $this->latest;
        }
        if (str_contains($url, '/contents/readme.txt')) {
            return $this->readme;
        }

        return new \WP_Error('unexpected', 'unexpected request to ' . $url);
    }

    /** @return array<string, mixed> */
    private function release(string $tag, array $overrides = []): array
    {
        $version = ltrim($tag, 'v');

        return array_merge([
            'tag_name'     => $tag,
            'html_url'     => 'https://github.com/wpmcp/wordpress-mcp/releases/tag/' . $tag,
            'draft'        => false,
            'prerelease'   => false,
            'published_at' => '2026-11-20T10:00:00Z',
            'body'         => "## What changed\n- Fixed **one** thing\n- Added `two`\n<script>alert(1)</script>",
            'assets'       => [
                [ 'name' => 'wpmcp-' . $version . '.zip' ],
                [ 'name' => 'wpmcp.zip' ],
                [ 'name' => 'safe-mcp-for-woocommerce.zip' ],
            ],
        ], $overrides);
    }

    private function json(int $code, $body): array
    {
        return [
            'headers'  => [],
            'body'     => is_string($body) ? $body : wp_json_encode($body),
            'response' => [ 'code' => $code, 'message' => '' ],
            'cookies'  => [],
        ];
    }

    private function basename(): string
    {
        return plugin_basename(WPMCP_FILE);
    }

    /** What core's update check would get from the filter. */
    private function ask(string $installed = WPMCP_VERSION, ?string $file = null)
    {
        return apply_filters(
            'update_plugins_' . GitHub_Releases::UPDATE_HOST,
            false,
            [ 'Version' => $installed, 'UpdateURI' => 'https://wpmcp-pro.com/' ],
            $file ?? $this->basename(),
            [ 'en_US' ]
        );
    }

    /** @var int|null The expiration of the last cache write. */
    private ?int $ttl = null;

    public function record_ttl($value, $expiration): void
    {
        $this->ttl = (int) $expiration;
    }

    /**
     * Seconds the last cache write asked for. Taken from core's
     * set_site_transient_{name} action, so it holds with or without a
     * persistent object cache (the full suite runs with one in some legs).
     */
    private function timeout(): int
    {
        return (int) $this->ttl;
    }

    public function test_the_filter_is_registered_on_the_update_uri_host(): void
    {
        $this->assertNotFalse(has_filter('update_plugins_wpmcp-pro.com', [ GitHub_Releases::class, 'filter_update' ]));
        $this->assertSame('wpmcp-pro.com', wp_parse_url(get_file_data(WPMCP_FILE, [ 'u' => 'Update URI' ])['u'], PHP_URL_HOST));
    }

    public function test_unlicensed_site_is_offered_a_newer_release(): void
    {
        $offer = $this->ask();

        $this->assertIsArray($offer);
        $this->assertSame('9.0.0', $offer['version']);
        $this->assertSame('9.0.0', $offer['new_version']);
        $this->assertSame('wpmcp', $offer['slug']);
        $this->assertSame('https://github.com/wpmcp/wordpress-mcp/releases/download/v9.0.0/wpmcp-9.0.0.zip', $offer['package']);
        $this->assertSame('https://github.com/wpmcp/wordpress-mcp/releases/tag/v9.0.0', $offer['url']);
        $this->assertSame('6.9', $offer['requires']);
        $this->assertSame('7.1', $offer['tested']);
        $this->assertSame('8.1', $offer['requires_php']);

        $this->assertCount(2, $this->requests);
        $this->assertSame(GitHub_Releases::API_LATEST, $this->requests[0]['url']);
        $this->assertStringContainsString('/contents/readme.txt?ref=v9.0.0', $this->requests[1]['url']);
        foreach ($this->requests as $request) {
            $this->assertSame('api.github.com', wp_parse_url($request['url'], PHP_URL_HOST));
            $this->assertSame(GitHub_Releases::USER_AGENT, $request['args']['user-agent']);
            $this->assertSame(5, $request['args']['timeout']);
            $this->assertSame('GET', $request['args']['method']);
            $this->assertEmpty($request['args']['body'] ?? null, 'nothing is sent');
        }
    }

    public function test_core_turns_the_offer_into_an_update_response(): void
    {
        // The same normalisation wp_update_plugins() applies to the answer.
        $update = (object) $this->ask();
        $this->assertTrue(isset($update->version));
        $this->assertTrue(version_compare($update->new_version, WPMCP_VERSION, '>'));
    }

    public function test_same_or_older_release_offers_nothing(): void
    {
        $this->latest = $this->json(200, $this->release('v9.0.0'));
        $this->assertFalse($this->ask('9.0.0'));
        $this->assertFalse($this->ask('9.1.0'));
    }

    /**
     * Issue #1180: the licensing SDK never starts its updater for this build
     * (is_premium is false and there is no wpmcp-pro folder), so a licensed
     * site takes its updates from the release too: wpmcp.zip is the same
     * full build the SDK would ship.
     */
    public function test_licensed_site_is_offered_a_newer_release(): void
    {
        Gate::set_pro_for_tests(true);

        $this->assertTrue(GitHub_Releases::active());
        $offer = $this->ask();
        $this->assertIsArray($offer);
        $this->assertSame('9.0.0', $offer['new_version']);
        $this->assertSame('https://github.com/wpmcp/wordpress-mcp/releases/download/v9.0.0/wpmcp-9.0.0.zip', $offer['package']);
    }

    public function test_the_filter_turns_it_off_for_a_licensed_site_too(): void
    {
        Gate::set_pro_for_tests(true);
        remove_filter('wpmcp_github_updates_enabled', '__return_true');
        add_filter('wpmcp_github_updates_enabled', '__return_false');

        $this->assertFalse($this->ask());
        $this->assertSame([], $this->requests);
    }

    public function test_a_build_other_than_the_full_one_is_never_offered_wpmcp_zip(): void
    {
        foreach ([ 'woocommerce', 'elementor' ] as $flavor) {
            Plugin::set_flavor_for_tests($flavor);
            try {
                $this->assertFalse(GitHub_Releases::active(), $flavor);
                $this->assertFalse($this->ask(), $flavor);
            } finally {
                Plugin::set_flavor_for_tests(null);
            }
        }
        $this->assertSame([], $this->requests);
    }

    public function test_the_filter_turns_it_off(): void
    {
        remove_filter('wpmcp_github_updates_enabled', '__return_true');
        add_filter('wpmcp_github_updates_enabled', '__return_false');

        $this->assertFalse($this->ask());
        $this->assertSame([], $this->requests);
    }

    public function test_git_checkout_is_off_by_default(): void
    {
        remove_filter('wpmcp_github_updates_enabled', '__return_true');
        $seen = null;
        $spy  = static function ($enabled) use (&$seen) {
            $seen = $enabled;
            return $enabled;
        };
        add_filter('wpmcp_github_updates_enabled', $spy);
        GitHub_Releases::active();
        remove_filter('wpmcp_github_updates_enabled', $spy);

        $this->assertSame(! file_exists(dirname(WPMCP_FILE) . '/.git'), $seen);
    }

    public function test_other_plugins_and_earlier_answers_pass_through(): void
    {
        $this->assertFalse($this->ask(WPMCP_VERSION, 'other/other.php'));
        $earlier = [ 'version' => '1.0.0' ];
        $this->assertSame($earlier, apply_filters('update_plugins_wpmcp-pro.com', $earlier, [ 'Version' => '0.1.0' ], $this->basename(), []));
        $this->assertSame([], $this->requests);
    }

    public function test_success_is_cached_for_twelve_hours(): void
    {
        $this->ask();
        $this->ask();
        $this->ask('1.0.0');

        $this->assertCount(2, $this->requests, 'one release fetch and one readme fetch, then the cache');
        $this->assertSame(12 * HOUR_IN_SECONDS, $this->timeout());
    }

    /** @return array<string, array{0: mixed}> */
    public static function failures(): array
    {
        return [
            'transport error'  => [ new \WP_Error('http_request_failed', 'timed out') ],
            'rate limited'     => [ [ 'code' => 403, 'body' => '{"message":"API rate limit exceeded"}' ] ],
            'server error'     => [ [ 'code' => 500, 'body' => '' ] ],
            'not json'         => [ [ 'code' => 200, 'body' => '<html>' ] ],
            'draft'            => [ [ 'code' => 200, 'release' => [ 'draft' => true ] ] ],
            'prerelease'       => [ [ 'code' => 200, 'release' => [ 'prerelease' => true ] ] ],
            'odd tag'          => [ [ 'code' => 200, 'release' => [ 'tag_name' => 'v9.0.0-beta.1' ] ] ],
            'no versioned zip' => [ [ 'code' => 200, 'release' => [ 'assets' => [ [ 'name' => 'wpmcp.zip' ] ] ] ] ],
            'directory-cut era' => [ [ 'code' => 200, 'tag' => 'v1.2.0' ] ],
        ];
    }

    /** @dataProvider failures */
    public function test_failures_offer_nothing_and_are_cached_for_an_hour($failure): void
    {
        if ($failure instanceof \WP_Error) {
            $this->latest = $failure;
        } elseif (isset($failure['body'])) {
            $this->latest = $this->json($failure['code'], $failure['body']);
        } else {
            $this->latest = $this->json(200, $this->release($failure['tag'] ?? 'v9.0.0', $failure['release'] ?? []));
        }

        $this->assertFalse($this->ask('0.1.0'));
        $this->assertFalse($this->ask('0.1.0'));

        $this->assertCount(1, $this->requests, 'the failure is cached, not retried on the next check');
        $this->assertSame(HOUR_IN_SECONDS, $this->timeout());
    }

    public function test_unreadable_readme_still_offers_the_update_without_requirements(): void
    {
        $this->readme = $this->json(404, '{"message":"Not Found"}');

        $offer = $this->ask();
        $this->assertSame('9.0.0', $offer['version']);
        $this->assertArrayNotHasKey('requires_php', $offer);
    }

    public function test_updates_screen_refreshes_an_answer_older_than_an_hour(): void
    {
        $this->ask();
        $this->assertCount(2, $this->requests);

        $run = function () {
            $this->within('load-update-core.php', function () {
                $this->ask();
            });
        };

        $run();
        $this->assertCount(2, $this->requests, 'a fresh answer is kept on the Updates screen');

        $cached            = get_site_transient(GitHub_Releases::CACHE_KEY);
        $cached['checked'] = time() - HOUR_IN_SECONDS - 1;
        set_site_transient(GitHub_Releases::CACHE_KEY, $cached, HOUR_IN_SECONDS);

        $run();
        $this->assertCount(4, $this->requests, 'an hour-old answer is refetched there');

        // Elsewhere the same old answer is not refetched.
        $cached            = get_site_transient(GitHub_Releases::CACHE_KEY);
        $cached['checked'] = time() - 2 * HOUR_IN_SECONDS;
        set_site_transient(GitHub_Releases::CACHE_KEY, $cached, HOUR_IN_SECONDS);
        $this->ask();
        $this->assertCount(4, $this->requests);
    }

    /**
     * Runs $fn inside action $hook with nothing else hooked there (core hooks
     * its own screens' callbacks on these actions).
     */
    private function within(string $hook, callable $fn): void
    {
        global $wp_filter;
        $backup = $wp_filter[ $hook ] ?? null;
        remove_all_actions($hook);
        add_action($hook, $fn);
        do_action($hook);
        remove_all_actions($hook);
        if (null !== $backup) {
            $wp_filter[ $hook ] = $backup;
        }
    }

    private function details($slug = 'wpmcp')
    {
        $result = null;
        $this->within('install_plugins_pre_plugin-information', function () use (&$result, $slug) {
            $result = apply_filters('plugins_api', false, 'plugin_information', (object) [ 'slug' => $slug ]);
        });

        return $result;
    }

    public function test_view_details_shows_the_release_notes(): void
    {
        $info = $this->details();

        $this->assertIsObject($info);
        $this->assertSame('wpmcp', $info->slug);
        $this->assertSame('9.0.0', $info->version);
        $this->assertSame('https://github.com/wpmcp/wordpress-mcp/releases/download/v9.0.0/wpmcp-9.0.0.zip', $info->download_link);
        $this->assertSame('8.1', $info->requires_php);
        $changelog = $info->sections['changelog'];
        $this->assertStringContainsString('<h4>What changed</h4>', $changelog);
        $this->assertStringContainsString('<li>Fixed <strong>one</strong> thing</li>', $changelog);
        $this->assertStringContainsString('<code>two</code>', $changelog);
        $this->assertStringNotContainsString('<script', $changelog);
    }

    public function test_view_details_leaves_other_lookups_alone(): void
    {
        $this->assertFalse(apply_filters('plugins_api', false, 'plugin_information', (object) [ 'slug' => 'wpmcp' ]), 'outside the dialog');
        $this->assertFalse($this->details('akismet'));
        remove_filter('wpmcp_github_updates_enabled', '__return_true');
        add_filter('wpmcp_github_updates_enabled', '__return_false');
        $this->assertFalse($this->details(), 'turned off');
        $this->assertSame([], $this->requests);
    }

    public function test_release_notes_links_are_https_only(): void
    {
        $html = GitHub_Releases::notes_html("See [the PR](https://github.com/wpmcp/wordpress-mcp/pull/1) and [x](javascript:alert(1))");
        $this->assertStringContainsString('<a href="https://github.com/wpmcp/wordpress-mcp/pull/1">the PR</a>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
    }

    public function test_the_zip_download_uses_the_fixed_user_agent(): void
    {
        $args = apply_filters('http_request_args', [ 'user-agent' => 'WordPress/6.9; https://example.org' ], 'https://github.com/wpmcp/wordpress-mcp/releases/download/v9.0.0/wpmcp-9.0.0.zip');
        $this->assertSame(GitHub_Releases::USER_AGENT, $args['user-agent']);

        $other = apply_filters('http_request_args', [ 'user-agent' => 'WordPress/6.9; https://example.org' ], 'https://downloads.wordpress.org/plugin/akismet.zip');
        $this->assertSame('WordPress/6.9; https://example.org', $other['user-agent']);
    }

    /** @return array{0: string, 1: \WP_Filesystem_Base|null} */
    private function filesystem(): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        global $wp_filesystem;
        $previous      = $wp_filesystem;
        $wp_filesystem = new \WP_Filesystem_Direct(null);
        $root          = trailingslashit(get_temp_dir()) . 'wpmcp-updater-' . wp_generate_password(8, false);
        mkdir($root);

        return [ $root, $previous ];
    }

    private function cleanup(string $root, $previous): void
    {
        global $wp_filesystem;
        $wp_filesystem->delete($root, true);
        $wp_filesystem = $previous;
    }

    public function test_update_keeps_the_installed_folder_name(): void
    {
        [ $root, $previous ] = $this->filesystem();
        try {
            mkdir($root . '/wpmcp');
            file_put_contents($root . '/wpmcp/wpmcp.php', '<?php');
            $folder = basename(dirname(WPMCP_FILE));

            $result = apply_filters('upgrader_source_selection', $root . '/wpmcp/', $root, null, [ 'plugin' => $this->basename() ]);

            $this->assertSame($root . '/' . $folder . '/', $result);
            $this->assertFileExists($root . '/' . $folder . '/wpmcp.php');

            // Already the right name: unchanged.
            $this->assertSame($result, apply_filters('upgrader_source_selection', $result, $root, null, [ 'plugin' => $this->basename() ]));
        } finally {
            $this->cleanup($root, $previous);
        }
    }

    public function test_update_refuses_a_package_without_wpmcp_php(): void
    {
        [ $root, $previous ] = $this->filesystem();
        try {
            mkdir($root . '/wpmcp-9.0.0');
            file_put_contents($root . '/wpmcp-9.0.0/readme.txt', 'x');

            $result = apply_filters('upgrader_source_selection', $root . '/wpmcp-9.0.0/', $root, null, [ 'plugin' => $this->basename() ]);
            $this->assertWPError($result);
            $this->assertSame('wpmcp_update_package', $result->get_error_code());

            // Other plugins' updates are never touched.
            $this->assertSame($root . '/wpmcp-9.0.0/', apply_filters('upgrader_source_selection', $root . '/wpmcp-9.0.0/', $root, null, [ 'plugin' => 'akismet/akismet.php' ]));

            // A licensed site's update is checked the same way (issue #1180).
            Gate::set_pro_for_tests(true);
            $this->assertWPError(apply_filters('upgrader_source_selection', $root . '/wpmcp-9.0.0/', $root, null, [ 'plugin' => $this->basename() ]));
        } finally {
            $this->cleanup($root, $previous);
        }
    }
}
