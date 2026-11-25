<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform\Session_Flow_Fixture;

/** Passes its input through one of its own methods. */
final class Own
{
    /** @param array<string, mixed> $args */
    public function handle(array $args): string
    {
        return $this->save($args, 'x');
    }

    /** @param array<string, mixed> $input */
    private function save(array $input, string $label): string
    {
        return $label . Writer::write(1, $input);
    }
}
