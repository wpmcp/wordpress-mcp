<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A Breakdance or Oxygen 6 template's settings row (`_<prefix>template_settings`)
 * as an array. The plugins save it through their own set_meta(), which
 * JSON-encodes its value, and most callers hand it a value that is already
 * JSON, so the row usually holds a JSON string of a JSON object; older rows
 * and other callers hold the object itself. Anything else reads as no settings.
 */
final class Engine_Template_Settings
{
    /** @return array<mixed> */
    public function decode(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? $stored : [];
    }
}
