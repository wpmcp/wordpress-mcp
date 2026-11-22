<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Which module owns a class, a namespace or a file.
 *
 * Sources, in order:
 *
 * 1. The compiled registries (build/registry/boot.php, and the Pro one when
 *    present): 'ownership' => ['namespaces' => [prefix => id],
 *    'paths' => [glob => id]] and 'modules' => [id => ['namespace' => ...]].
 *    A namespace match wins, the longest prefix first; a path glob (fnmatch,
 *    relative to the repository root, as the generator matches them) is
 *    tried on the class's file only when no namespace matched.
 * 2. The namespace convention: a class under one of the convention roots
 *    (WPMCP\Modules\ by default) belongs to the module named by the next
 *    segment, `WPMCP\Modules\<Id>\`. This covers a checkout with no compiled
 *    registry and a module the registry has not seen yet. The id is then the
 *    segment itself, so it is only compared with other convention ids.
 *
 * A missing or unreadable registry file is skipped, never an error: the gate
 * then runs on the convention alone.
 */
final class ModuleMap
{
    /** @var array<string, string> namespace prefix => module id, longest first */
    private array $namespaces = [];

    /** @var array<string, string> path glob => module id */
    private array $paths = [];

    /** @var array<string, string> module id => namespace prefix */
    private array $module_namespaces = [];

    private string $repo_root;

    /** @var array<string, ?ModuleRef> */
    private array $class_cache = [];

    /**
     * @param list<string> $registryFiles
     * @param list<string> $conventionRoots
     */
    public function __construct(
        array $registryFiles,
        string $repoRoot,
        private readonly array $conventionRoots,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $real = realpath($repoRoot);
        $this->repo_root = rtrim(false === $real ? $repoRoot : $real, '/');
        foreach ($registryFiles as $file) {
            $this->load($file);
        }
        uksort($this->namespaces, static fn (string $a, string $b): int => [strlen($b), $a] <=> [strlen($a), $b]);
    }

    /** The module a namespace (without the trailing backslash) belongs to. */
    public function moduleOfNamespace(string $namespace): ?ModuleRef
    {
        return $this->byName(trim($namespace, '\\') . '\\');
    }

    /** The module the code at a scope belongs to: by namespace, then by file. */
    public function moduleOfScope(Scope $scope): ?ModuleRef
    {
        $namespace = $scope->getNamespace();
        $module = null === $namespace ? null : $this->moduleOfNamespace($namespace);
        return $module ?? $this->moduleOfFile($scope->getFile());
    }

    /** The module that owns a file by one of its legacy path globs. */
    public function moduleOfFile(string $file): ?ModuleRef
    {
        $relative = $this->relative($file);
        if (null === $relative) {
            return null;
        }
        foreach ($this->paths as $glob => $id) {
            if (fnmatch($glob, $relative)) {
                return new ModuleRef($id, $this->module_namespaces[ $id ] ?? '');
            }
        }
        return null;
    }

    /** The module owning a class: by namespace, then by the file declaring it. */
    public function moduleOfClass(string $class): ?ModuleRef
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);
        if (array_key_exists($key, $this->class_cache)) {
            return $this->class_cache[ $key ];
        }
        $module = $this->byName($class);
        if (null === $module && [] !== $this->paths && $this->reflectionProvider->hasClass($class)) {
            $file = $this->reflectionProvider->getClass($class)->getFileName();
            $module = null === $file ? null : $this->moduleOfFile($file);
        }
        return $this->class_cache[ $key ] = $module;
    }

    private function byName(string $name): ?ModuleRef
    {
        foreach ($this->namespaces as $prefix => $id) {
            if (0 === strncasecmp($name, $prefix, strlen($prefix))) {
                return new ModuleRef($id, $prefix);
            }
        }
        foreach ($this->conventionRoots as $root) {
            $root = trim($root, '\\') . '\\';
            if (0 !== strncasecmp($name, $root, strlen($root))) {
                continue;
            }
            $rest = substr($name, strlen($root));
            $pos = strpos($rest, '\\');
            if (false === $pos || 0 === $pos) {
                continue;
            }
            $segment = substr($rest, 0, $pos);
            return new ModuleRef($segment, $root . $segment . '\\');
        }
        return null;
    }

    private function relative(string $file): ?string
    {
        $real = realpath($file);
        $file = false === $real ? $file : $real;
        return str_starts_with($file, $this->repo_root . '/') ? substr($file, strlen($this->repo_root) + 1) : null;
    }

    private function load(string $file): void
    {
        if (! is_file($file) || ! is_readable($file)) {
            return;
        }
        $boot = (static function (string $path): mixed {
            return require $path;
        })($file);
        if (! is_array($boot)) {
            return;
        }
        $ownership = is_array($boot['ownership'] ?? null) ? $boot['ownership'] : [];
        foreach ((is_array($ownership['namespaces'] ?? null) ? $ownership['namespaces'] : []) as $prefix => $id) {
            if (is_string($prefix) && is_string($id) && '' !== trim($prefix, '\\')) {
                $this->namespaces[ trim($prefix, '\\') . '\\' ] = $id;
            }
        }
        foreach ((is_array($ownership['paths'] ?? null) ? $ownership['paths'] : []) as $glob => $id) {
            if (is_string($glob) && is_string($id)) {
                $this->paths[ $glob ] = $id;
            }
        }
        foreach ((is_array($boot['modules'] ?? null) ? $boot['modules'] : []) as $id => $module) {
            if (is_string($id) && is_array($module) && is_string($module['namespace'] ?? null)) {
                $namespace = trim($module['namespace'], '\\') . '\\';
                $this->module_namespaces[ $id ] = $namespace;
                $this->namespaces[ $namespace ] ??= $id;
            }
        }
    }
}
