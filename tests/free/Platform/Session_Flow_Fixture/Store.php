<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Reads the session id from its parameter, as an injected service. */
final class Store
{
    /** @param array<string, mixed> $input */
    public function put(array $input): string
    {
        return (string) ($input['session_id'] ?? 'default');
    }
}
