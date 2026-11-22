<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * The GitHub release update check (src/Updates) belongs to the self-hosted
 * build only. Every wordpress.org style build is updated by the directory
 * (guideline 8), so the shared strip policy removes the directory and no
 * flavor loader registers it. tests/free/Platform/WporgStripTest checks the
 * removed paths are gone from a real stage, and the wordpress.org build runs
 * scripts/lib/updater-gate.sh over its stage on top of that.
 */
class GitHubReleasesBuildPolicyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_the_shared_strip_policy_removes_src_updates(): void
    {
        $policy = require self::root() . '/scripts/flavors/wporg/policy.php';
        $this->assertContains('src/Updates', $policy['removed_paths']);
    }

    public function test_only_the_self_hosted_loader_registers_it(): void
    {
        $self_hosted = (string) file_get_contents(self::root() . '/wpmcp.php');
        $this->assertStringContainsString('\\WPMCP\\Updates\\GitHub_Releases::register();', $self_hosted);

        foreach ([ 'scripts/flavors/wporg/wpmcp.php', 'scripts/flavors/woocommerce/safe-mcp-for-woocommerce.php' ] as $loader) {
            $code = (string) file_get_contents(self::root() . '/' . $loader);
            $this->assertNotSame('', $code, "$loader is missing");
            $this->assertStringNotContainsString('GitHub_Releases', $code, "$loader must not register the GitHub update check");
            $this->assertStringNotContainsString('Updates\\', $code, $loader);
        }
    }

    public function test_the_hook_host_is_the_update_uri_host(): void
    {
        $lib = (string) file_get_contents(self::root() . '/scripts/lib/release-flavors.sh');
        $this->assertSame(1, preg_match("/^WPMCP_UPDATE_URI='([^']+)'$/m", $lib, $m));
        $class = (string) file_get_contents(self::root() . '/src/Updates/GitHub_Releases.php');
        $this->assertSame(1, preg_match("/UPDATE_HOST = '([^']+)'/", $class, $h));
        $this->assertSame(parse_url($m[1], PHP_URL_HOST), $h[1]);
    }

    public function test_it_trips_no_directory_updater_marker_in_the_self_hosted_tree(): void
    {
        // Plugin_Updater_Check's error-level markers stay out of this file
        // too, so the source-tree compliance run keeps it a best-practice note.
        $code = (string) file_get_contents(self::root() . '/src/Updates/GitHub_Releases.php');
        foreach (\WPMCP\Compliance\Rules\Updater_Rule::UPDATER_PATTERNS as $pattern) {
            $this->assertDoesNotMatchRegularExpression('/' . $pattern . '/i', $code, $pattern);
        }
    }
}
