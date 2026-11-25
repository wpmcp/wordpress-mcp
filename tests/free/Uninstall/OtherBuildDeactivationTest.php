<?php

namespace WPMCP\Tests\Free\Uninstall;

use WPMCP\Deactivator;

/**
 * Deactivating one WPMCP build while another is still active hands the
 * site to that build on the next request (the premium copy deactivating
 * the free one it replaced, say). They share every cron hook, so the
 * events stay; with no other build active, deactivation clears them as
 * before.
 */
class OtherBuildDeactivationTest extends \WP_UnitTestCase
{
    private const HOOK = 'wpmcp_run_backup_schedule';

    /** @var string[] fixture plugin directories */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_clear_scheduled_hook(self::HOOK, [ 'database' ]);
        wp_schedule_event(time() + 600, 'daily', self::HOOK, [ 'database' ]);
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook(self::HOOK, [ 'database' ]);
        foreach ($this->dirs as $dir) {
            foreach ((array) glob($dir . '/{,.}[!.]*', GLOB_BRACE) as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
        update_option('active_plugins', []);
        parent::tearDown();
    }

    /** Install a plugin main file and return its active-plugins basename. */
    private function install(string $main, ?string $flavor): string
    {
        $slug = 'wpmcp-fixture-' . wp_generate_password(6, false, false);
        $dir  = WP_PLUGIN_DIR . '/' . $slug;
        wp_mkdir_p($dir);
        $this->dirs[] = $dir;
        $header       = "<?php\n/**\n * Plugin Name: deactivation fixture\n";
        if (null !== $flavor) {
            $header .= " * WPMCP Flavor: {$flavor}\n";
        }
        file_put_contents($dir . '/' . $main, $header . " */\n");

        return $slug . '/' . $main;
    }

    public function test_with_another_build_active_deactivation_keeps_the_events(): void
    {
        update_option('active_plugins', [ $this->install('wpmcp.php', 'full') ]);

        Deactivator::deactivate(false);

        $this->assertIsInt(wp_next_scheduled(self::HOOK, [ 'database' ]), 'The build that stays lost its cron event.');
    }

    public function test_an_installed_but_inactive_build_does_not_keep_the_events(): void
    {
        $this->install('wpmcp.php', 'full');
        update_option('active_plugins', []);

        Deactivator::deactivate(false);

        $this->assertFalse(wp_next_scheduled(self::HOOK, [ 'database' ]));
    }

    public function test_an_active_unrelated_plugin_does_not_keep_the_events(): void
    {
        update_option('active_plugins', [ $this->install('wpmcp-companion.php', null) ]);

        Deactivator::deactivate(false);

        $this->assertFalse(wp_next_scheduled(self::HOOK, [ 'database' ]));
    }

    public function test_the_check_runs_before_any_event_is_cleared(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Deactivator.php');
        $guard  = strpos($source, 'wpmcp_other_build_active(dirname(__DIR__))');

        $this->assertNotFalse($guard);
        $this->assertLessThan((int) strpos($source, 'Cron_Registry::for_each_site'), $guard);
    }
}
