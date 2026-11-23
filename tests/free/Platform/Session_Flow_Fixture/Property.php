<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input to a typed, injected property. */
final class Property
{
    public function __construct(private readonly Store $store = new Store())
    {
    }

    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return $this->store->put($args);
    }
}
