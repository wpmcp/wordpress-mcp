<?php

namespace WPMCP\Tests\Free\Cron;

use WPMCP\Auth\Oauth_Gc;
use WPMCP\Cron_Registry;
use WPMCP\Tests\Support\Edition_Tree;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Backup_Schedule;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Run_Restore_Job;
use WPMCP\Tools\Redirects\Broken_Link_Scan_Store;
use WPMCP\Tools\Redirects\Run_Broken_Link_Scan;

/**
 * Guard for the plugin's cron hook registry (issue #468).
 *
 * Deactivation and uninstall clear exactly the hooks Cron_Registry lists, so
 * a new scheduling call whose hook is missing from it would leave events
 * behind again. This walks every PHP file under src/ at token level, finds
 * each WP-Cron and Action Scheduler scheduling call, resolves the hook
 * argument (a string literal or a class constant such as self::HOOK), and
 * fails on any hook the registry does not list. It also fails on a registry
 * entry nothing schedules any more, so the list cannot rot.
 *
 * This half scans the public sources (Edition_Tree::public_sources()) and
 * names Pro classes only by their values. Cron_Registry is public code and
 * lists the hooks only Pro code schedules too; those are PRO_SCHEDULED_HOOKS
 * here, and tests/pro/Cron/CronHookRegistryProTest checks that the Pro
 * sources schedule them, scans the Pro sources the same way and pins the
 * values to the Pro classes' constants.
 */
class CronHookRegistryTest extends \WP_UnitTestCase
{
    /** Scheduling functions => zero-based position of the hook argument. */
    public const SCHEDULERS = [
        'wp_schedule_event'            => 2,
        'wp_schedule_single_event'     => 1,
        'wp_reschedule_event'          => 2,
        'as_schedule_single_action'    => 1,
        'as_schedule_recurring_action' => 2,
        'as_schedule_cron_action'      => 2,
        'as_enqueue_async_action'      => 0,
    ];

    /**
     * Files allowed to schedule a hook the plugin does not own. The cron
     * tools schedule whatever hook the agent names, on the site's behalf;
     * those events are the site's, not the plugin's, and survive it.
     */
    private const DYNAMIC_HOOK_FILES = [
        'src/Tools/Cron/Schedule_Event.php',
        // Undo of an Action Scheduler cancel re-creates the site's own action under its own hook.
        'src/Safety/Action_Scheduler_Snapshot.php',
        // backup-now (#694) queues UpdraftPlus's own run hook; deactivating WP MCP must not cancel a backup.
        'src/Integrations/UpdraftPlus_Status.php',
    ];

    /**
     * Registry hooks that only Pro code schedules, by value: the WP-CLI job
     * runner (Run_Cli_Job::HOOK) and the media optimization job
     * (Optimize_Media_Job::HOOK). The public scan must not find them
     * scheduled, and the Pro half must.
     */
    public const PRO_SCHEDULED_HOOKS = [
        'wpmcp_run_cli_job',
        'wpmcp_run_optimize_media_job',
    ];

    public function test_every_scheduled_hook_is_in_the_registry(): void
    {
        $calls = self::scheduling_calls();
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

    public function test_every_registry_hook_is_scheduled_somewhere(): void
    {
        $scheduled = array_unique(array_filter(array_column(self::scheduling_calls(), 'hook')));
        $stale     = array_values(array_diff(Cron_Registry::hooks(), $scheduled, self::PRO_SCHEDULED_HOOKS));

        $this->assertSame([], $stale, 'Cron_Registry lists hooks nothing in src/ schedules: ' . implode(', ', $stale));
        $this->assertSame([], array_values(array_diff(self::PRO_SCHEDULED_HOOKS, Cron_Registry::hooks())), 'PRO_SCHEDULED_HOOKS lists hooks Cron_Registry does not.');
        $this->assertSame([], array_values(array_intersect(self::PRO_SCHEDULED_HOOKS, $scheduled)), 'PRO_SCHEDULED_HOOKS lists hooks public code schedules; drop them from the list.');
    }

    public function test_the_registry_names_the_known_hooks(): void
    {
        $hooks = Cron_Registry::hooks();

        foreach ([
            Oauth_Gc::HOOK,
            Backup_Schedule::HOOK,
            Run_Backup_Job::HOOK,
            Run_Restore_Job::HOOK,
            Run_Broken_Link_Scan::HOOK,
            ...self::PRO_SCHEDULED_HOOKS,
        ] as $hook) {
            $this->assertContains($hook, $hooks);
        }
        $this->assertSame(array_values(array_unique($hooks)), $hooks, 'The registry lists a hook twice.');
    }

    /**
     * The registry names the job stores by option, not by class, so it can
     * ship in builds that prune a job's classes. Pin those names to the
     * stores' own constants.
     */
    public function test_job_hooks_point_at_their_stores(): void
    {
        $this->assertSame(
            [
                Run_Backup_Job::HOOK       => [ Backup_Job_Store::OPTION, 'jobs' ],
                Run_Restore_Job::HOOK      => [ Backup_Job_Store::OPTION, 'jobs' ],
                // Run_Static_Export::HOOK (issue #854), named by its value: the class is Pro only.
                'wpmcp_run_static_export'  => [ Backup_Job_Store::OPTION, 'jobs' ],
                // Run_Cli_Job::HOOK => Cli_Job_Store::OPTION, by value (Pro classes; the Pro half pins them).
                'wpmcp_run_cli_job'        => [ 'wpmcp_cli_jobs', 'jobs' ],
                Run_Broken_Link_Scan::HOOK => [ Broken_Link_Scan_Store::OPTION, 'scans' ],
                // Optimize_Media_Job::HOOK and ::OPTION, by value (Pro class; the Pro half pins them).
                'wpmcp_run_optimize_media_job' => [ 'wpmcp_optimize_media_jobs', 'jobs' ],
            ],
            Cron_Registry::JOB_HOOKS
        );
    }

    public function test_the_scanner_resolves_both_hook_shapes(): void
    {
        $src = "<?php\nnamespace WPMCP\\Auth;\nclass Demo { const HOOK = 'x';\n"
            . "function a() { wp_schedule_event(time(), 'daily', 'wpmcp_literal_hook'); }\n"
            . "function b() { \\wp_schedule_single_event(time(), Oauth_Gc::HOOK, [1]); }\n"
            . "function c() { wp_schedule_single_event(time(), \$hook); }\n"
            . "function wp_schedule_event() {}\n"
            . "function d() { \$this->wp_schedule_event(1, 2, 'not_a_call'); }\n}\n";

        $calls = self::calls_in('src/Demo.php', $src);

        $this->assertSame(['wpmcp_literal_hook', Oauth_Gc::HOOK, null], array_column($calls, 'hook'));
    }

    /**
     * @param array<string, string>|null $sources src/... path => absolute path (Edition_Tree); the public sources when null
     * @return array<int, array{file:string,line:int,function:string,hook:?string,expr:string}>
     */
    public static function scheduling_calls(?array $sources = null): array
    {
        $calls = [];
        foreach ($sources ?? Edition_Tree::public_sources() as $relative => $absolute) {
            $calls = array_merge($calls, self::calls_in($relative, (string) file_get_contents($absolute)));
        }
        return $calls;
    }

    /** @return array<int, array{file:string,line:int,function:string,hook:?string,expr:string}> */
    public static function calls_in(string $relative, string $src): array
    {
        $tokens    = array_values(array_filter(
            token_get_all($src),
            static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $namespace = '';
        $class     = '';
        $uses      = [];
        $calls     = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[ $i ];
            if (! is_array($t)) {
                continue;
            }
            if (T_NAMESPACE === $t[0] && is_array($tokens[ $i + 1 ] ?? null)) {
                $namespace = $tokens[ $i + 1 ][1];
                continue;
            }
            if (T_USE === $t[0] && '' === $class && is_array($tokens[ $i + 1 ] ?? null)) {
                $name = ltrim($tokens[ $i + 1 ][1], '\\');
                $as   = $tokens[ $i + 2 ] ?? null;
                $uses[ (is_array($as) && T_AS === $as[0]) ? $tokens[ $i + 3 ][1] : substr((string) strrchr('\\' . $name, '\\'), 1) ] = $name;
                continue;
            }
            if (T_CLASS === $t[0] && is_array($tokens[ $i + 1 ] ?? null) && T_STRING === $tokens[ $i + 1 ][0]) {
                $class = ltrim($namespace . '\\' . $tokens[ $i + 1 ][1], '\\');
                continue;
            }
            if (! in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $function = strtolower(ltrim($t[1], '\\'));
            if (! isset(self::SCHEDULERS[ $function ]) || '(' !== ($tokens[ $i + 1 ] ?? null)) {
                continue;
            }
            $prev = $tokens[ $i - 1 ] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $args  = self::arguments($tokens, $i + 1);
            $arg   = $args[ self::SCHEDULERS[ $function ] ] ?? [];
            $calls[] = [
                'file'     => $relative,
                'line'     => $t[2],
                'function' => $function,
                'hook'     => self::resolve($arg, $namespace, $class, $uses),
                'expr'     => implode('', array_map(static fn ($x) => is_array($x) ? $x[1] : $x, $arg)),
            ];
        }

        return $calls;
    }

    /** The argument token lists of the call whose '(' is at $open. */
    private static function arguments(array $tokens, int $open): array
    {
        $args  = [[]];
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $tokens[ $i ];
            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                if (++$depth === 1) {
                    continue;
                }
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif (',' === $t && 1 === $depth) {
                $args[] = [];
                continue;
            }
            $args[ count($args) - 1 ][] = $t;
        }
        return $args;
    }

    /** A string literal or Class::CONST argument, resolved; null for anything dynamic. */
    private static function resolve(array $arg, string $namespace, string $class, array $uses): ?string
    {
        if (1 === count($arg) && is_array($arg[0]) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0]) {
            return stripslashes(substr($arg[0][1], 1, -1));
        }
        if (3 !== count($arg) || ! is_array($arg[0]) || ! is_array($arg[1]) || T_DOUBLE_COLON !== $arg[1][0] || ! is_array($arg[2])) {
            return null;
        }

        $name = $arg[0][1];
        if (in_array(strtolower($name), ['self', 'static'], true)) {
            $fqn = $class;
        } elseif ('\\' === $name[0]) {
            $fqn = ltrim($name, '\\');
        } else {
            $first = explode('\\', $name)[0];
            $fqn   = isset($uses[ $first ])
                ? $uses[ $first ] . substr($name, strlen($first))
                : ltrim($namespace . '\\' . $name, '\\');
        }

        $constant = $fqn . '::' . $arg[2][1];
        return defined($constant) ? (string) constant($constant) : null;
    }
}
