<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry;

use PHPUnit\Framework\TestCase;
use WPMCP\Registry\Scanner;

final class ScannerTest extends TestCase
{
    public function test_finds_only_files_that_name_a_kernel_attribute(): void
    {
        $found = (new Scanner())->scan([__DIR__ . '/Fixture/Other']);

        self::assertSame(
            [
                'WPMCP\\Tests\\Kernel\\Registry\\Fixture\\Other\\Other_Module',
                'WPMCP\\Tests\\Kernel\\Registry\\Fixture\\Other\\Tools\\Ping',
            ],
            array_keys($found)
        );
        self::assertStringEndsWith('Fixture/Other/Module.php', $found['WPMCP\\Tests\\Kernel\\Registry\\Fixture\\Other\\Other_Module']);
    }

    public function test_finds_several_classes_in_one_file_and_ignores_interfaces(): void
    {
        $found = (new Scanner())->scan([__DIR__ . '/Invalid/Duplicate_Ability']);

        self::assertSame(['Module_Class', 'One', 'Two'], array_map(static fn (string $c): string => substr($c, strrpos($c, '\\') + 1), array_keys($found)));
        self::assertSame([], (new Scanner())->scan([__DIR__ . '/Fixture/Demo/Adapter/Demo_Adapter.php']));
    }

    public function test_a_missing_root_is_empty(): void
    {
        self::assertSame([], (new Scanner())->scan([__DIR__ . '/no-such-dir']));
    }

    public function test_skips_excluded_paths(): void
    {
        self::assertSame([], (new Scanner(exclude: [__DIR__ . '/Fixture/Other']))->scan([__DIR__ . '/Fixture/Other']));
    }
}
