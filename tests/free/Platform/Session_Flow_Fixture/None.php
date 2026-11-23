<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Never reads the session id. */
final class None
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return (string) ($args['id'] ?? '');
    }
}
