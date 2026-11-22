<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

/**
 * One module as the rules see it: its registry id and its namespace prefix
 * (with the trailing backslash).
 */
final class ModuleRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $namespace,
    ) {
    }

    public function owns(string $class): bool
    {
        return '' !== $this->namespace && str_starts_with(ltrim($class, '\\'), $this->namespace);
    }

    /**
     * The layer of a class of this module: the first namespace segment below
     * the module namespace ('Tools', 'Adapter', ...), or '' for a class at
     * the module root (the Module class). Null when the class is not in the
     * module namespace (a legacy file the module owns by path).
     */
    public function layerOf(string $class): ?string
    {
        $class = ltrim($class, '\\');
        if (! $this->owns($class)) {
            return null;
        }
        $rest = substr($class, strlen($this->namespace));
        $pos = strpos($rest, '\\');
        return false === $pos ? '' : substr($rest, 0, $pos);
    }
}
