<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Hooks;

use WPMCP\Api\V1\Attribute\Cron;
use WPMCP\Api\V1\Attribute\Hook;
use WPMCP\Api\V1\Attribute\On_Rollback;
use WPMCP\Tests\Kernel\Registry\Fixture\Demo\Adapter\Demo_Adapter;

final class Things_Hooks
{
    public function __construct(private readonly Demo_Adapter $things)
    {
    }

    #[Hook('template_redirect', priority: 1, contexts: ['frontend'])]
    public function maybe_redirect(): void
    {
        $this->things->things(1);
    }

    #[Hook('the_content', filter: true)]
    public static function filter_content(string $content): string
    {
        return $content;
    }

    #[Cron('wpmcp_demo_cron')]
    public function run_cron(): void
    {
    }

    #[On_Rollback]
    #[On_Rollback('wpmcp_rollback_post_restored')]
    public static function after_restore(mixed $a = null, mixed $b = null): void
    {
    }
}
