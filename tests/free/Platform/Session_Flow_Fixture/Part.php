<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes on a new array, not its input. */
final class Part
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        $part = [ 'id' => $args['id'] ?? 0 ];
        return Writer::write(3, $part);
    }
}
