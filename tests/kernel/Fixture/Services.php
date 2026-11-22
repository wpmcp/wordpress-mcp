<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Fixture;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

interface Clock
{
    public function now(): int;
}

final class Fixed_Clock implements Clock
{
    public function now(): int
    {
        return 42;
    }
}

final class No_Deps
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function handle(array $input): array
    {
        return ['echo' => $input];
    }

    /** @param array<string, mixed> $input @return list<array<string, mixed>> */
    public function objects(array $input): array
    {
        return [['type' => 'post', 'id' => (int) ($input['post_id'] ?? 0)]];
    }

    /** @param array<string, mixed> $input */
    public function op_capability(array $input): ?string
    {
        return isset($input['operation']) ? 'manage_options' : null;
    }
}

final class Needs_Clock
{
    public function __construct(public readonly Clock $clock, public readonly No_Deps $other)
    {
    }

    public function tick(int $by = 1): int
    {
        return $this->clock->now() + $by;
    }
}

final class Static_Hooks
{
    /** @var list<array<int, mixed>> */
    public static array $calls = [];

    public static function on_init(mixed ...$args): void
    {
        self::$calls[] = $args;
    }
}
