<?php

declare(strict_types=1);

namespace WPMCP\Bench;

/**
 * One benchmark task (issue #1130): its fixtures, the full-surface call
 * sequence an agent would make, the list-tools searches the compact surface
 * needs, and the check that the task was really done.
 *
 * The in-process bench (BenchTest) and the HTTP mode's site half
 * (remote.php) run the same instance, so seed() and verify() may only use
 * WordPress and the active plugins, never the bench's transport.
 *
 * REPO-ONLY, like everything under tools/.
 */
interface Scenario
{
    public function id(): int;

    public function slug(): string;

    public function title(): string;

    /** @return list<string> What the site lacks for this scenario (a plugin, a tool); [] when it can run. */
    public function missing(): array;

    /** @return array<string, mixed> The fixtures: ids and values the steps and verify() read. */
    public function seed(): array;

    /**
     * @param array<string, mixed> $fixtures
     * @return array<int, array<string, mixed>> The full-surface steps (see Runner).
     */
    public function steps(array $fixtures): array;

    /** @return list<string> list-tools search terms an agent would try on the compact surface. */
    public function searches(): array;

    /**
     * @param array<string, mixed> $fixtures
     * @param array<string, mixed> $state    The run's results by their 'as' key.
     * @return ?string The problem, or null when the task was done.
     */
    public function verify(array $fixtures, array $state): ?string;
}
