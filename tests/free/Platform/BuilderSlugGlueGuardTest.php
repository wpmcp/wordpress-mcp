<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

/**
 * Text merges of the builder lists once glued entries together: the
 * detect-builder description read "...|gutenberg|classicPost builder: ..."
 * three times over, and the Builder_Design refusal listed "betheme or
 * brizycornerstone, betheme or siteorigincornerstone". This scans every string
 * literal under src/ for a builder slug run into another slug or into a
 * capitalised word, and for a string that names one slug twice while listing
 * several. The slugs come from the code: what Builder_Detector returns and
 * Builder_Design::BUILDERS.
 */
final class BuilderSlugGlueGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    /** @return string[] */
    private static function slugs(): array
    {
        $detector = (string) file_get_contents(self::SRC . '/Tools/Builders/Builder_Detector.php');
        $design   = (string) file_get_contents(self::SRC . '/Tools/Builders/Builder_Design.php');
        preg_match_all("/return '([a-z][a-z-]+)';/", $detector, $returned);
        preg_match('/const BUILDERS = \[([^\]]*)\]/', $design, $list);
        preg_match_all("/'([a-z][a-z-]+)'/", $list[1] ?? '', $listed);

        $slugs = array_values(array_unique(array_merge($returned[1], $listed[1])));
        usort($slugs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $slugs;
    }

    /** @return array<string, array{int, string}> "file:line" => [line, literal] */
    private static function literals(): array
    {
        $out   = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = 'src' . substr($file->getPathname(), strlen(self::SRC));
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $out[$relative . ':' . $token[2] . ':' . md5($token[1])] = [$token[2], $token[1]];
                }
            }
        }

        return $out;
    }

    public function test_the_slug_list_is_found(): void
    {
        $slugs = self::slugs();

        $this->assertContains('elementor', $slugs);
        $this->assertContains('visual-composer', $slugs);
        $this->assertContains('classic', $slugs);
    }

    public function test_no_string_runs_a_builder_slug_into_the_next_word(): void
    {
        $any   = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), self::slugs()));
        $glued = '/(?<![A-Za-z0-9_-])(?:' . $any . ')(?:(?:' . $any . ')(?![a-z0-9_])|[A-Z])/';
        $found = [];
        foreach (self::literals() as $where => [$line, $literal]) {
            if (preg_match($glued, $literal, $m)) {
                $found[] = strstr($where, ':' . md5($literal), true) . ': "' . $m[0] . '"';
            }
        }

        $this->assertSame([], $found, "Builder slugs glued to the next word (a text merge dropped a separator):\n" . implode("\n", $found));
    }

    public function test_no_string_lists_a_builder_slug_twice(): void
    {
        $slugs = self::slugs();
        $found = [];
        foreach (self::literals() as $where => [$line, $literal]) {
            $counts = [];
            foreach ($slugs as $slug) {
                $n = preg_match_all('/(?<![A-Za-z0-9_-])' . preg_quote($slug, '/') . '(?![a-z0-9_-])/', $literal);
                if ($n > 0) {
                    $counts[$slug] = $n;
                }
            }
            $twice = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
            if (count($counts) >= 4 && [] !== $twice) {
                $found[] = strstr($where, ':' . md5($literal), true) . ': ' . implode(', ', $twice);
            }
        }

        $this->assertSame([], $found, "A builder list names a slug twice (a text merge repeated part of it):\n" . implode("\n", $found));
    }
}
