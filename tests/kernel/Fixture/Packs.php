<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Fixture;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Generic.Files.OneObjectStructurePerFile.MultipleFound

use WPMCP\Api\V1\Contract\Operation_Provider;

final class Links_Pack implements Operation_Provider
{
    public static int $calls = 0;
    public static bool $active = true;

    public static function active(): bool
    {
        return self::$active;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        ++self::$calls;
        return [
            'links-list' => ['mode' => 'read', 'handler' => static fn (): array => []],
            'links-delete' => ['mode' => 'destructive', 'handler' => static fn (): array => []],
        ];
    }
}

final class Reviews_Pack implements Operation_Provider
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [
            'reviews-list' => ['mode' => 'read', 'handler' => static fn (): array => []],
            // Same name as a Links op: today's array_merge lets the later one win.
            'links-list' => ['mode' => 'read', 'handler' => static fn (): string => 'reviews'],
        ];
    }
}

final class Pro_Pack implements Operation_Provider
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return ['pro-op' => ['mode' => 'write', 'handler' => static fn (): array => []]];
    }
}

/** A contributor whose op metadata is static and whose handlers are injected instance methods. */
final class Injected_Ops implements Operation_Provider
{
    public function __construct(private readonly No_Deps $dep)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [
            'injected-read' => ['mode' => 'read', 'handler' => 'read', 'validate' => 'check', 'description' => 'kept as is'],
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function read(array $args): array
    {
        return $this->dep->handle($args);
    }

    /** @param array<string, mixed> $args */
    public function check(array $args): ?array
    {
        return null;
    }
}
