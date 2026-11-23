<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Reads a different key. */
final class Other_Key
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return (string) ($args['chat_session'] ?? 'default');
    }
}
