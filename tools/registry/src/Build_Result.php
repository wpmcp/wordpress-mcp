<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * What a run produced: errors (the build failed when any), notes (counts
 * and warnings), the files written and whether it was skipped.
 */
final class Build_Result
{
    /**
     * @param list<string> $errors
     * @param list<string> $notes
     * @param list<string> $written
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $notes = [],
        public readonly array $written = [],
        public readonly bool $skipped = false,
    ) {
    }

    public function ok(): bool
    {
        return [] === $this->errors;
    }
}
