<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * bin/make-module.php must produce a module that passes its own tests as
 * generated: it writes a throwaway module into a temporary fixture root
 * (never src/Modules), builds its surface.lock.php and runs its fast lane
 * in a separate process, which must exit 0.
 */
final class MakeModuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpmcp-make-module-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return array{int, string} */
    private function make(string ...$args): array
    {
        $repo = dirname(__DIR__, 3);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/make-module.php') . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' --root=' . escapeshellarg($this->root . '/modules')
            . ' --tests-root=' . escapeshellarg($this->root . '/tests')
            . ' --namespace=Acme\\Scaffold\\Modules --tests-namespace=Acme\\Scaffold\\Tests 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** @return iterable<string, list<string>> */
    public static function shapes(): iterable
    {
        yield 'integration with writes' => ['Acme_Links', '--type=integration', '--writes', '--plugin=acme-links/acme-links.php', '--plugin-name=Acme Links', '--slug=acme-links'];
        yield 'read-only integration on the theme pair' => ['Acme_Theme', '--dispatcher=theme', '--tier=pro'];
        yield 'feature in the woocommerce flavor' => ['Acme_Feature', '--type=feature', '--flavors=woocommerce'];
    }

    /** @dataProvider shapes */
    public function test_the_scaffold_is_green_as_generated(string $id, string ...$args): void
    {
        [$code, $out] = $this->make($id, ...$args);

        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/OK.*Tests: \d+|OK \(\d+ tests/s', $out);
        self::assertFileExists($this->root . "/modules/{$id}/surface.lock.php");
        self::assertFileExists($this->root . "/modules/{$id}/Module.php");
        self::assertFileExists($this->root . "/tests/{$id}/Conformance_Test.php");
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if (str_ends_with($file->getPathname(), '.php')) {
                $code_text = (string) file_get_contents($file->getPathname());
                self::assertStringContainsString('declare(strict_types=1);', $code_text, $file->getPathname());
                self::assertDoesNotMatchRegularExpression('/\x{2014}|\x{2013}/u', $code_text, 'no em or en dashes in generated code');
            }
        }
    }

    public function test_refuses_to_overwrite_an_existing_module(): void
    {
        $this->make('Acme_Twice', '--no-run');
        [$code, $out] = $this->make('Acme_Twice', '--no-run');

        self::assertSame(2, $code);
        self::assertStringContainsString('already exists', $out);
    }

    public function test_rejects_a_bad_id(): void
    {
        [$code, $out] = $this->make('acme links', '--no-run');

        self::assertSame(2, $code);
        self::assertStringContainsString('Upper_Snake', $out);
    }
}
