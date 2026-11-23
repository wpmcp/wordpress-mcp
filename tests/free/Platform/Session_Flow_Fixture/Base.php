<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input to a private method of the parent class. */
abstract class Base
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return $this->route('write', $args);
    }

    /** @param array<string, mixed> $args */
    private function route(string $channel, array $args): string
    {
        return $channel . (string) ($args['session_id'] ?? 'default');
    }
}
