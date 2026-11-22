<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * Finds the classes that carry a kernel attribute with a token scan, so
 * only those files are ever loaded and reflected. A file qualifies when it
 * names the WPMCP\Api\V1\Attribute namespace (a use statement or a fully
 * qualified name); every class declared in it is returned, interfaces,
 * traits and enums are not.
 */
final class Scanner
{
    public const ATTRIBUTE_NAMESPACE = 'WPMCP\\Api\\V1\\Attribute';

    /** @var list<string> */
    private readonly array $exclude;

    /** @param list<string> $exclude paths (files or directories) never scanned */
    public function __construct(array $exclude = [])
    {
        $this->exclude = array_values(array_filter(array_map(static fn (string $p): string|false => realpath($p), $exclude)));
    }

    /**
     * @param list<string> $roots directories or files
     * @return array<class-string, string> class => file, in path order
     */
    public function scan(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            foreach ($this->files($root) as $file) {
                $files[ $file ] = true;
            }
        }
        $files = array_keys($files);
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            if (! str_contains($code, self::ATTRIBUTE_NAMESPACE)) {
                continue;
            }
            foreach (self::classes($code) as $class) {
                $out[ $class ] = $file;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $real = realpath($root);
        if (false === $real || $this->excluded($real)) {
            return [];
        }
        if (is_file($real)) {
            return str_ends_with($real, '.php') ? [$real] : [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php') && ! $this->excluded($path)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    private function excluded(string $path): bool
    {
        foreach ($this->exclude as $ex) {
            if ($path === $ex || str_starts_with($path, $ex . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fully qualified names of the classes declared in a file.
     *
     * @return list<class-string>
     */
    public static function classes(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $namespace = '';
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $t = $tokens[ $i ];
            if ($t->is(T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; ++$j) {
                    if ($tokens[ $j ]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[ $j ]->text;
                    } elseif ($tokens[ $j ]->is([';', '{'])) {
                        break;
                    }
                }
                continue;
            }
            if (! $t->is(T_CLASS)) {
                continue;
            }
            // Skip ::class and anonymous classes.
            $prev = self::previous($tokens, $i);
            if (null !== $prev && $prev->is([T_DOUBLE_COLON, T_NEW])) {
                continue;
            }
            $next = self::next($tokens, $i);
            if (null !== $next && $next->is(T_STRING)) {
                /** @var class-string $class */
                $class = ('' === $namespace ? '' : $namespace . '\\') . $next->text;
                $out[] = $class;
            }
        }
        return $out;
    }

    /** @param list<\PhpToken> $tokens */
    private static function previous(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (! $tokens[ $j ]->isIgnorable()) {
                return $tokens[ $j ];
            }
        }
        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $i): ?\PhpToken
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; ++$j) {
            if (! $tokens[ $j ]->isIgnorable()) {
                return $tokens[ $j ];
            }
        }
        return null;
    }
}
