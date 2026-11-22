<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A refused Oxygen 6 request (issue #805): thrown by the operation classes
 * and returned by Oxygen6_Operations / Oxygen6_Reads as an
 * invalid_oxygen_request error, so the message reaches the caller as JSON
 * and is never rendered as HTML.
 */
final class Oxygen6_Request_Error extends \InvalidArgumentException
{
    public static function raise(string $message): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by Oxygen6_Operations / Oxygen6_Reads and returned as a JSON error, never rendered.
        throw new self($message);
    }
}
