<?php

namespace WPMCP\Tests\Pro\Cron;

use WPMCP\Cron_Registry;
use WPMCP\Tests\Free\Cron\CronHookRegistryTest;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Media\Optimize_Media_Job;

/**
 * The Pro half of the cron hook registry guard (issue #468,
 * tests/free/Cron/CronHookRegistryTest): every hook the Pro sources schedule
 * is in Cron_Registry, every hook the public half lists as Pro-scheduled is
 * really scheduled here, and the values the public half names are pinned to
 * the Pro classes' constants.
 */
class CronHookRegistryProTest extends \WP_UnitTestCase
{
    /**
     * Pro files allowed to schedule a hook the plugin does not own (none
     * today; see DYNAMIC_HOOK_FILES in the public half).
     */
    private const DYNAMIC_HOOK_FILES = [];

    protected function setUp(): void
    {
        parent::setUp();
        Edition_Tree::require_pro($this);
    }

    public function test_every_scheduled_hook_is_in_the_registry(): void
    {
        $calls = CronHookRegistryTest::scheduling_calls(Edition_Tree::pro_sources());
        $this->assertNotEmpty($calls, 'The scan found no scheduling calls at all, so it is not looking in the right place.');

        $registry   = Cron_Registry::hooks();
        $unresolved = [];
        $missing    = [];
        foreach ($calls as $call) {
            if (null === $call['hook']) {
                if (! in_array($call['file'], self::DYNAMIC_HOOK_FILES, true)) {
                    $unresolved[] = sprintf('%s:%d %s(%s)', $call['file'], $call['line'], $call['function'], $call['expr']);
                }
                continue;
            }
            if (! in_array($call['hook'], $registry, true) && ! in_array($call['file'], self::DYNAMIC_HOOK_FILES, true)) {
                $missing[] = sprintf('%s:%d schedules "%s"', $call['file'], $call['line'], $call['hook']);
            }
        }

        $this->assertSame([], $unresolved, "Scheduling calls whose hook could not be resolved to a constant or literal:\n" . implode("\n", $unresolved));
        $this->assertSame([], $missing, "Hooks scheduled in src/ but missing from Cron_Registry, so deactivation would leave them behind:\n" . implode("\n", $missing));
    }

    public function test_every_pro_registry_hook_is_scheduled_somewhere(): void
    {
        $scheduled = array_unique(array_filter(array_column(CronHookRegistryTest::scheduling_calls(Edition_Tree::pro_sources()), 'hook')));
        $stale     = array_values(array_diff(CronHookRegistryTest::PRO_SCHEDULED_HOOKS, $scheduled));

        $this->assertSame([], $stale, 'Cron_Registry lists hooks nothing in src/ schedules: ' . implode(', ', $stale));
    }

    public function test_the_registry_names_the_known_hooks(): void
    {
        $hooks = Cron_Registry::hooks();
        foreach ([ Run_Cli_Job::HOOK, Optimize_Media_Job::HOOK ] as $hook) {
            $this->assertContains($hook, $hooks);
            $this->assertContains($hook, CronHookRegistryTest::PRO_SCHEDULED_HOOKS);
        }
    }

    public function test_job_hooks_point_at_their_stores(): void
    {
        $this->assertSame([ Cli_Job_Store::OPTION, 'jobs' ], Cron_Registry::JOB_HOOKS[ Run_Cli_Job::HOOK ] ?? null);
        $this->assertSame([ Optimize_Media_Job::OPTION, 'jobs' ], Cron_Registry::JOB_HOOKS[ Optimize_Media_Job::HOOK ] ?? null);
    }
}
