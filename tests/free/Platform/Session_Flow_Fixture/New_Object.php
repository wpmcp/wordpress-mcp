<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input to a method of a new object. */
final class New_Object
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return (new Store())->put($args);
    }
}
