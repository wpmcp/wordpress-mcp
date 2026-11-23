<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Admin\Connection;

use WPMCP\Admin\Connection\Activity_Store;
use WPMCP\Admin\Connection\Mcp_Activity;

/**
 * An in-memory Activity_Store for the fast tier: what was saved, by user and
 * password, and how many writes it took.
 */
final class Fake_Activity_Store implements Activity_Store
{
    /** @var array<string, Mcp_Activity> */
    private array $rows = [];

    private int $saves = 0;

    public function find(int $user_id, string $uuid): ?Mcp_Activity
    {
        return $this->rows[ $user_id . '|' . $uuid ] ?? null;
    }

    public function save(int $user_id, string $uuid, Mcp_Activity $activity): void
    {
        $this->rows[ $user_id . '|' . $uuid ] = $activity;
        ++$this->saves;
    }

    public function forget(int $user_id, string $uuid): void
    {
        unset($this->rows[ $user_id . '|' . $uuid ]);
    }

    public function saves(): int
    {
        return $this->saves;
    }
}
