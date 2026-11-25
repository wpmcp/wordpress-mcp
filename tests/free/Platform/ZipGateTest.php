<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

/**
 * scripts/lib/zip-gate.sh, as the release builds call it: zip_includes()
 * fails a zip that lacks a required entry (the compiled module registry,
 * build/registry/boot.php), and zip_excludes() a zip that carries a banned
 * file. Runs the real shell functions on small zips built here.
 */
final class ZipGateTest extends \WP_UnitTestCase
{
    private string $dir = '';

    public function set_up(): void
    {
        parent::set_up();
        if ('' === trim((string) shell_exec('command -v zip unzip 2>/dev/null'))) {
            $this->markTestSkipped('zip and unzip are needed.');
        }
        $this->dir = sys_get_temp_dir() . '/wpmcp-zip-gate-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/stage/wpmcp/build/registry', 0777, true);
        mkdir($this->dir . '/stage/wpmcp/src', 0777, true);
        file_put_contents($this->dir . '/stage/wpmcp/src/Plugin.php', "<?php\n");
    }

    public function tear_down(): void
    {
        if ('' !== $this->dir) {
            shell_exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tear_down();
    }

    /** @return array{0: int, 1: string} exit status and stderr of one gate call */
    private function gate(string $call): array
    {
        $zip = $this->dir . '/out.zip';
        @unlink($zip);
        shell_exec(sprintf('cd %s && zip -rq %s wpmcp', escapeshellarg($this->dir . '/stage'), escapeshellarg($zip)));
        $script = sprintf('. %s; %s %s', escapeshellarg(dirname(__DIR__, 3) . '/scripts/lib/zip-gate.sh'), $call, escapeshellarg($zip));
        $out = [];
        $status = 0;
        exec('bash -c ' . escapeshellarg($script . ' wpmcp/build/registry/boot.php') . ' 2>&1', $out, $status);
        return [$status, implode("\n", $out)];
    }

    public function test_a_zip_without_the_registry_fails_zip_includes(): void
    {
        [$status, $err] = $this->gate('zip_includes');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('missing: wpmcp/build/registry/boot.php', $err);
    }

    public function test_a_zip_with_the_registry_passes_zip_includes(): void
    {
        file_put_contents($this->dir . '/stage/wpmcp/build/registry/boot.php', "<?php return [];\n");

        [$status] = $this->gate('zip_includes');

        $this->assertSame(0, $status);
    }

    /**
     * Each release build checks for the registry under the root folder of
     * the zip it just built, never under a literal folder name: the premium
     * zip is rooted at wpmcp-pro/, so a literal wpmcp/ path failed every
     * `scripts/build-release.sh --premium` run.
     *
     * @return array<string, array{0: string}>
     */
    public static function release_builds(): array
    {
        return [
            'self-hosted (free and premium)' => ['scripts/build-release.sh'],
            'WooCommerce'                    => ['scripts/build-woo-release.sh'],
            'wordpress.org'                  => ['scripts/build-wporg-release.sh'],
        ];
    }

    /**
     * @dataProvider release_builds
     */
    public function test_each_release_build_checks_the_registry_under_its_own_zip_root(string $script): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $script);

        $this->assertSame(1, preg_match_all('/^zip_includes "\$ZIP" "([^"]+)"/m', $source, $calls), $script . ' should check the registry exactly once');
        $this->assertMatchesRegularExpression('#^\$(FOLDER|SLUG)/build/registry/boot\.php$#', $calls[1][0], $script . ' must name the zip root through its variable, not a literal folder');
    }

    public function test_an_unreadable_zip_is_not_reported_clean(): void
    {
        $out = [];
        $status = 0;
        $script = sprintf('. %s; zip_includes %s wpmcp/build/registry/boot.php', escapeshellarg(dirname(__DIR__, 3) . '/scripts/lib/zip-gate.sh'), escapeshellarg($this->dir . '/none.zip'));
        exec('bash -c ' . escapeshellarg($script) . ' 2>&1', $out, $status);

        $this->assertSame(2, $status);
    }
}
