<?php

declare(strict_types=1);

namespace WPMCP\Integrations\Support;

use WPMCP\Integrations\Plugin_Refusal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The plugin missing refusals a module may depend on (design section 1.5),
 * worded and shaped exactly as Plugin_Refusal words them. An injectable
 * service over Plugin_Refusal until that class moves into this namespace.
 */
final class Plugin_Refusals
{
    /**
     * One named plugin is needed.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    public function make(string $code, string $plugin): array
    {
        return Plugin_Refusal::make($code, $plugin);
    }

    /**
     * Any one of several plugins would do.
     *
     * @param array<string, mixed> $data
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    public function any_of(string $code, string $message, array $data = []): array
    {
        return Plugin_Refusal::any_of($code, $message, $data);
    }
}
