<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

/**
 * scripts/lib/premium-only-tag.sh, as scripts/build-release.sh --premium
 * calls it. Freemius builds a free and a premium package from each upload
 * and leaves out of the free one every path the main file header lists
 * under @fs_premium_only. The premium zip lists the Pro tree and the premium
 * marker there, so the free package Freemius offers as a download never
 * carries private Pro code. Runs the real shell functions on fixture files.
 */
final class PremiumOnlyTagTest extends \WP_UnitTestCase
{
    private string $dir = '';

    private const HEADER = "<?php\n/**\n * Plugin Name: WPMCP\n * Version: 9.9.9\n * WPMCP Flavor: full\n */\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n/** Not the header. */\n";

    public function set_up(): void
    {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/wpmcp-premium-only-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    public function tear_down(): void
    {
        if ('' !== $this->dir) {
            shell_exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tear_down();
    }

    /** @return array{0: int, 1: string} exit status and output of one call */
    private function run_lib(string $call, string $file): array
    {
        $script = sprintf('. %s; %s %s', escapeshellarg(dirname(__DIR__, 3) . '/scripts/lib/premium-only-tag.sh'), $call, escapeshellarg($file));
        $out = [];
        $status = 0;
        exec('bash -c ' . escapeshellarg($script) . ' 2>&1', $out, $status);
        return [$status, implode("\n", $out)];
    }

    private function fixture(string $content): string
    {
        $file = $this->dir . '/wpmcp.php';
        file_put_contents($file, $content);
        return $file;
    }

    public function test_the_tag_goes_into_the_plugin_header_and_names_the_pro_tree_and_the_marker(): void
    {
        $file = $this->fixture(self::HEADER);

        [$status] = $this->run_lib('add_premium_only_tag', $file);
        $content = (string) file_get_contents($file);

        $this->assertSame(0, $status);
        $this->assertStringContainsString(" * WPMCP Flavor: full\n *\n * @fs_premium_only /pro/, /.wpmcp-pro\n */\n", $content, 'the last lines of the first docblock');
        $this->assertSame(1, substr_count($content, '@fs_premium_only'));
        $this->assertStringEndsWith("/** Not the header. */\n", $content, 'later docblocks stay as they are');
    }

    public function test_adding_it_twice_changes_nothing(): void
    {
        $file = $this->fixture(self::HEADER);

        $this->run_lib('add_premium_only_tag', $file);
        $once = (string) file_get_contents($file);
        [$status] = $this->run_lib('add_premium_only_tag', $file);

        $this->assertSame(0, $status);
        $this->assertSame($once, (string) file_get_contents($file));
    }

    public function test_the_check_passes_only_for_a_header_that_names_both_paths(): void
    {
        $file = $this->fixture(self::HEADER);

        [$before] = $this->run_lib('assert_premium_only_tag', $file);
        $this->run_lib('add_premium_only_tag', $file);
        [$after] = $this->run_lib('assert_premium_only_tag', $file);
        $this->fixture(str_replace(" */\nif", " *\n * @fs_premium_only /pro/\n */\nif", self::HEADER));
        [$partial] = $this->run_lib('assert_premium_only_tag', $file);

        $this->assertSame(1, $before);
        $this->assertSame(0, $after);
        $this->assertSame(1, $partial, 'the marker must be listed too');
    }

    public function test_a_file_without_a_header_docblock_is_an_error(): void
    {
        $file = $this->fixture("<?php\necho 'no header';\n");

        [$status] = $this->run_lib('add_premium_only_tag', $file);

        $this->assertSame(2, $status);
        $this->assertStringNotContainsString('@fs_premium_only', (string) file_get_contents($file));
    }
}
