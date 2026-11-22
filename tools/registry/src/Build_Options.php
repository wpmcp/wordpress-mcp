<?php

declare(strict_types=1);

namespace WPMCP\Registry;

/**
 * One generator run. $roots are scanned for attributed classes; $out gets
 * the aggregates (boot.php, abilities.php, ops/, data files); $artifacts
 * gets the repo-only artifacts; locks go next to each Module class unless
 * $locks_root is set (tests). $check compares locks and writes nothing.
 * $auto skips quietly when no module exists (the post-autoload-dump hook).
 */
final class Build_Options
{
    /**
     * @param list<string>          $roots
     * @param list<string>          $exclude
     * @param array<string, mixed>  $config  overrides tools/registry/config.php
     */
    public function __construct(
        public readonly array $roots,
        public readonly string $out,
        public readonly string $flavor = 'full',
        public readonly ?string $artifacts = null,
        public readonly ?string $locks_root = null,
        public readonly string $repo_root = '',
        public readonly bool $check = false,
        public readonly bool $auto = false,
        public readonly bool $addon = false,
        public readonly array $exclude = [],
        public readonly ?int $tools_list_budget = null,
        public readonly array $config = [],
        public readonly bool $locks = true,
    ) {
    }
}
