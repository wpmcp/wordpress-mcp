<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Where a class or function comes from:
 *
 * - PHP: a builtin (PHP core or an extension, as PHPStan's stubs know it).
 * - WORDPRESS: declared in a file matching one of the WordPress stub
 *   patterns (fnmatch, default `* /php-stubs/wordpress-stubs/ *` without the
 *   spaces), which is where szepeviktor/phpstan-wordpress loads core from.
 * - WPMCP: a class under WPMCP\, or a function under WPMCP\ or named
 *   wpmcp_*. Decided by name, so it holds without reflection.
 * - FOREIGN: everything else, including a symbol PHPStan cannot find (no
 *   stubs for the host plugin). For a module this is the host plugin or a
 *   vendor library.
 */
final class SymbolOrigin
{
    public const PHP = 'php';
    public const WORDPRESS = 'wordpress';
    public const WPMCP = 'wpmcp';
    public const FOREIGN = 'foreign';

    /** @param list<string> $wordpressStubPatterns */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $wordpressStubPatterns,
    ) {
    }

    public function ofClass(string $class): string
    {
        $class = ltrim($class, '\\');
        if (0 === strncasecmp($class, 'WPMCP\\', 6)) {
            return self::WPMCP;
        }
        if (! $this->reflectionProvider->hasClass($class)) {
            return self::FOREIGN;
        }
        $reflection = $this->reflectionProvider->getClass($class);
        if ($reflection->isBuiltin()) {
            return self::PHP;
        }
        return $this->isWordPressFile($reflection->getFileName()) ? self::WORDPRESS : self::FOREIGN;
    }

    /**
     * @param ?string $namespace the namespace the call is made in; an
     *                           unqualified name falls back to the global one, as PHP does
     */
    public function ofFunction(Name $name, ?string $namespace): string
    {
        $plain = ltrim($name->toString(), '\\');
        $candidates = $name->isFullyQualified() || null === $namespace || '' === $namespace
            ? [$plain]
            : [trim($namespace, '\\') . '\\' . $plain, $plain];
        foreach ($candidates as $candidate) {
            $fq = new FullyQualified($candidate);
            if (! $this->reflectionProvider->hasFunction($fq, null)) {
                continue;
            }
            if ($this->isWpmcpFunction($candidate)) {
                return self::WPMCP;
            }
            $function = $this->reflectionProvider->getFunction($fq, null);
            if ($function->isBuiltin()) {
                return self::PHP;
            }
            return $this->isWordPressFile($function->getFileName()) ? self::WORDPRESS : self::FOREIGN;
        }
        return $this->isWpmcpFunction($plain) ? self::WPMCP : self::FOREIGN;
    }

    private function isWpmcpFunction(string $name): bool
    {
        return 0 === strncasecmp($name, 'WPMCP\\', 6) || 0 === strncasecmp($name, 'wpmcp_', 6);
    }

    private function isWordPressFile(?string $file): bool
    {
        if (null === $file) {
            return false;
        }
        foreach ($this->wordpressStubPatterns as $pattern) {
            if (fnmatch($pattern, $file, FNM_NOESCAPE)) {
                return true;
            }
        }
        return false;
    }
}
