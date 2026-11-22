<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * Text merges of the builder lists once glued entries together: the
 * detect-builder description read "...|gutenberg|classicPost builder: ..."
 * three times over, and the Builder_Design refusal listed "betheme or
 * brizycornerstone, betheme or siteorigincornerstone". This scans every string
 * literal under src/ for a builder slug run into another slug or into a
 * capitalised word (unless the word is an identifier the code uses as a
 * literal of its own, such as a stored key), and for a string that names one
 * slug twice while listing several. The slugs come from the code: what Builder_Detector returns and
 * Builder_Design::BUILDERS (a Pro file, read wherever it is when present).
 *
 * This half scans the public sources (Edition_Tree::public_sources()); the
 * Pro sources are scanned by tests/pro/Platform/BuilderSlugGlueGuardProTest
 * with the same checks.
 */
final class BuilderSlugGlueGuardTest extends TestCase
{
    /** @return string[] */
    public static function slugs(): array
    {
        $all      = Edition_Tree::all_sources();
        $detector = (string) file_get_contents($all['src/Tools/Builders/Builder_Detector.php'] ?? '');
        $design   = isset($all['src/Tools/Builders/Builder_Design.php']) ? (string) file_get_contents($all['src/Tools/Builders/Builder_Design.php']) : '';
        preg_match_all("/return '([a-z][a-z-]+)';/", $detector, $returned);
        preg_match('/const BUILDERS = \[([^\]]*)\]/', $design, $list);
        preg_match_all("/'([a-z][a-z-]+)'/", $list[1] ?? '', $listed);

        $slugs = array_values(array_unique(array_merge($returned[1], $listed[1])));
        usort($slugs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $slugs;
    }

    /**
     * @param array<string, string>|null $sources src/... path => absolute path (Edition_Tree); the public sources when null
     * @return array<string, array{int, string}> "file:line" => [line, literal]
     */
    public static function literals(?array $sources = null): array
    {
        $out = [];
        foreach ($sources ?? Edition_Tree::public_sources() as $relative => $absolute) {
            foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $out[$relative . ':' . $token[2] . ':' . md5($token[1])] = [$token[2], $token[1]];
                }
            }
        }

        return $out;
    }

    /**
     * Literals that run a builder slug into the next word.
     *
     * @param array<string, array{int, string}> $literals
     * @return string[]
     */
    public static function glued(array $literals): array
    {
        $any   = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), self::slugs()));
        $glued = '/(?<![A-Za-z0-9_-])(?:' . $any . ')(?:(?:' . $any . ')(?![a-z0-9_])|[A-Z])/';
        // Identifiers the code itself uses as one literal (a stored key such
        // as Oxygen's 'breakdanceData') are names, not glued prose, also
        // where a message quotes them.
        $identifiers = [];
        foreach ($literals as [$line, $literal]) {
            if (1 === preg_match('/^([\'"])([A-Za-z_][A-Za-z0-9_]*)\1$/', $literal, $m)) {
                $identifiers[ $m[2] ] = true;
            }
        }
        $found = [];
        foreach ($literals as $where => [$line, $literal]) {
            if (! preg_match_all($glued, $literal, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$match, $offset]) {
                preg_match('/[A-Za-z0-9_]*/', $literal, $word, 0, $offset);
                if (! isset($identifiers[ $word[0] ])) {
                    $found[] = strstr($where, ':' . md5($literal), true) . ': "' . $match . '"';
                }
            }
        }

        return $found;
    }

    /**
     * Literals that list several builder slugs and name one twice.
     *
     * @param array<string, array{int, string}> $literals
     * @return string[]
     */
    public static function repeated(array $literals): array
    {
        $slugs = self::slugs();
        $found = [];
        foreach ($literals as $where => [$line, $literal]) {
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

        return $found;
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
        $found = self::glued(self::literals());

        $this->assertSame([], $found, "Builder slugs glued to the next word (a text merge dropped a separator):\n" . implode("\n", $found));
    }

    public function test_no_string_lists_a_builder_slug_twice(): void
    {
        $found = self::repeated(self::literals());

        $this->assertSame([], $found, "A builder list names a slug twice (a text merge repeated part of it):\n" . implode("\n", $found));
    }
}
