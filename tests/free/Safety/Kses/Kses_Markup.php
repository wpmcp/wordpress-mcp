<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Safety\Kses;

/**
 * Markup fixtures for the issue #1243 kses tests. A class, not trait
 * constants: those are PHP 8.2 syntax and the floor is PHP 8.1.
 */
final class Kses_Markup
{
    /** Markup kses rewrites: the script is dropped, its text kept. */
    public const UNSAFE = '<p>Kept</p><script>alert(1)</script>';

    /** What a page stored by a user allowed to holds, which kses would strip. */
    public const STORED = '<p>Stored</p><script>window.stored = true;</script>';
}
