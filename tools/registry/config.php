<?php

/**
 * Generator configuration: the dispatcher pairs that exist before their
 * #[Dispatcher] lands (phase A), and the shrinking allowlists for the
 * description cap and duplicate operation names. An entry here is a known
 * exception, never a new one: the lists may only shrink.
 */

declare(strict_types=1);

return [
    'legacy_dispatchers' => ['plugin-data', 'theme', 'woo', 'block-suites'],
    'description_cap' => 300,
    'long_descriptions' => [],
    'duplicate_ops' => [],
    'tools_list_budget' => 216000,
];
