<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry;

use PHPUnit\Framework\TestCase;
use WPMCP\Registry\Php_Exporter;

final class PhpExporterTest extends TestCase
{
    public function test_exports_a_plain_literal_that_round_trips(): void
    {
        $value = ['a' => 1, 'b' => [true, false, null], 'c' => 1.5, 'd' => "it's", 'e' => [], 'list' => ['x', 'y'], 7 => 'int key'];
        $code = Php_Exporter::file($value, 'test header');

        self::assertStringStartsWith("<?php\n// test header\n", $code);
        self::assertStringNotContainsString('array (', $code);
        $file = tempnam(sys_get_temp_dir(), 'wpmcp-export');
        file_put_contents((string) $file, $code);
        self::assertSame($value, require $file);
        unlink((string) $file);
    }

    public function test_lists_are_written_without_keys(): void
    {
        self::assertSame("[\n    'x',\n    'y',\n]", Php_Exporter::export(['x', 'y']));
        self::assertSame('[]', Php_Exporter::export([]));
    }

    public function test_refuses_objects_and_closures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Php_Exporter::export(['f' => static fn (): int => 1]);
    }
}
