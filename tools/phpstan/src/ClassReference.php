<?php

declare(strict_types=1);

namespace WPMCP\PHPStan;

/** One class named in source: the resolved name, where, and how it is used. */
final class ClassReference
{
    public function __construct(
        public readonly string $class,
        public readonly int $line,
        public readonly string $kind,
    ) {
    }
}
