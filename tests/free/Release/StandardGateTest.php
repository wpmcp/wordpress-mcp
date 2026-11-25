<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * bin/standard.sh --diff decides which gates a change needs from the files
 * it changed and the untracked files beside them (rule 13: a change to the
 * tool surface runs the golden group). The decision has to hold in a
 * worktree that has both: with pipefail, `{ git diff; git ls-files; } |
 * grep -q .` read as false when grep had exited before the second git was
 * done writing (SIGPIPE), so the golden gate was skipped without a word.
 *
 * `bin/standard.sh --diff=<ref> --plan` prints the gates a run would run,
 * one `gate: <name>` line each, and runs none of them. Each test runs it in
 * a scratch git repository holding a copy of the script, removed after.
 */
final class StandardGateTest extends TestCase
{
    private const GOLDEN = 'gate: golden baseline (rule 13)';

    private string $repo = '';

    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/wpmcp-standard-plan-' . bin2hex(random_bytes(6));
        mkdir($this->repo . '/bin', 0777, true);
        copy(dirname(__DIR__, 3) . '/bin/standard.sh', $this->repo . '/bin/standard.sh');
        $this->git('init -q');
        $this->git('config user.email gate@example.test');
        $this->git('config user.name Gate');
        $this->write('src/Tools/Existing.php', "<?php\n");
        $this->write('docs/notes.md', "Notes\n");
        $this->git('add -A');
        $this->git('commit -q -m base');
        $this->base = trim($this->git('rev-parse HEAD'));
    }

    protected function tearDown(): void
    {
        if ('' !== $this->repo && is_dir($this->repo)) {
            exec('rm -rf ' . escapeshellarg($this->repo));
        }
        parent::tearDown();
    }

    private function git(string $args): string
    {
        exec('git -C ' . escapeshellarg($this->repo) . ' ' . $args . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, "git {$args} failed:\n" . implode("\n", $output));

        return implode("\n", $output);
    }

    private function write(string $path, string $content): void
    {
        $file = $this->repo . '/' . $path;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    /** @return list<string> the gate lines of a plan run against the base commit */
    private function plan(): array
    {
        exec('bash ' . escapeshellarg($this->repo . '/bin/standard.sh') . ' --diff=' . escapeshellarg($this->base) . ' --plan 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, "--plan failed:\n" . implode("\n", $output));

        return array_values(array_filter($output, static fn (string $line): bool => str_starts_with($line, 'gate: ')));
    }

    /** Untracked files enough that the second git writes after grep has seen the first line. */
    private function untracked(string $dir, int $count = 400): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->write("{$dir}/Untracked_{$i}_" . str_repeat('x', 40) . '.php', "<?php\n");
        }
    }

    public function test_a_tracked_change_beside_untracked_files_still_runs_the_golden_gate(): void
    {
        $this->write('src/Tools/Existing.php', "<?php\n// changed\n");
        $this->untracked('src/Tools');

        $this->assertContains(self::GOLDEN, $this->plan());
    }

    public function test_untracked_surface_files_alone_run_the_golden_gate(): void
    {
        $this->untracked('src/Tools', 3);

        $this->assertContains(self::GOLDEN, $this->plan());
    }

    public function test_a_change_outside_the_surface_runs_no_golden_gate(): void
    {
        $this->write('docs/notes.md', "More notes\n");
        $this->untracked('docs', 3);

        $plan = $this->plan();
        $this->assertNotContains(self::GOLDEN, $plan);
        $this->assertContains('gate: central-list guard', $plan);
    }

    public function test_new_module_files_run_the_registry_check_and_gate_rule_files_their_tests(): void
    {
        $this->write('src/Tools/Existing.php', "<?php\n// changed\n");
        $this->untracked('src/Modules/Example', 3);
        $this->untracked('tools/phpstan/src', 3);

        $plan = $this->plan();
        $this->assertContains('gate: registry check (every flavor)', $plan);
        $this->assertContains('gate: gate rule tests', $plan);
        $this->assertContains(self::GOLDEN, $plan);
    }
}
