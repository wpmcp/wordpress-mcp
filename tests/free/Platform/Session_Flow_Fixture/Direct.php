<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Reads the session id itself. */
final class Direct
{
    /**
     * @param array<string, mixed> $args
     * @return array{session_id: string}
     */
    public function handle(array $args): array
    {
        return [ 'session_id' => (string) ($args['session_id'] ?? 'default') ];
    }
}
