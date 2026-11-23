<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Reads the session id from its second parameter. */
final class Writer
{
    /** @param array<string, mixed> $args */
    public static function write(int $id, array $args): string
    {
        return $id . ':' . (string) ($args['session_id'] ?? 'default');
    }
}
