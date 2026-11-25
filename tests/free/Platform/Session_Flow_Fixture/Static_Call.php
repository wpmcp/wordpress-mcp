<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input to a static method that reads it. */
final class Static_Call
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return Writer::write((int) ($args['id'] ?? 0), $args);
    }
}
