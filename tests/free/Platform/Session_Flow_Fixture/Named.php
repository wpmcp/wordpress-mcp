<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input as a named argument. */
final class Named
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return Writer::write(args: $args, id: 2);
    }
}
