<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

use WPMCP\Safety\Kses_Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Kses_Safety checks for plugin-data and theme packs (issue #1243):
 * plain post content an op stores is reported (what kses removes, for the
 * op's result), and data kept in post_content as text (JSON settings) is
 * refused as an Operation_Refused, which the dispatcher returns in its
 * error envelope, when kses would change it for a user without
 * unfiltered_html.
 *
 * The packs are static, so this is their one composition point for the
 * collaborator; it holds no state.
 */
final class Kses_Check
{
    /**
     * What the save's kses will remove from plain post content for this user
     * (issue #1243), for the op's result: empty when it is stored as
     * planned, otherwise sanitized:true and a short list of what goes.
     * WordPress sanitizes such content as its editor does (#440); the op
     * goes ahead and says so.
     *
     * @param mixed $content The content argument as passed (open JSON input); null when the op does not write content.
     * @return array{sanitized?: true, removed?: list<string>}
     */
    public static function report(int $post_id, mixed $content): array
    {
        if (null === $content || ! is_scalar($content)) {
            return [];
        }

        return (new Kses_Safety())->sanitization($post_id, (string) $content);
    }

    /**
     * Data kept in post_content as text (JSON settings): any change the save
     * would make is refused (Kses_Safety::exact_refusal()).
     *
     * @throws Operation_Refused When the save would not store it byte for byte.
     */
    public static function assert_exact(int $post_id, string $data, string $subject): void
    {
        $refusal = (new Kses_Safety())->exact_refusal($post_id, $data, $subject);
        if (null !== $refusal) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the dispatcher returns the message as data in its JSON envelope; it is never echoed.
            throw Operation_Refused::from_error($refusal);
        }
    }
}
