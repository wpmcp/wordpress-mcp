<?php

declare(strict_types=1);

namespace WPMCP\Admin\Connection;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Code blocks and copy rows on the Connection screen. A block that carries
 * the application password wraps it in a span, so the script can hide it on
 * screen by default and show it on request; Copy always copies the full
 * text. Without JavaScript the password stays visible, because the reader
 * needs it, and the Copy and Show buttons stay hidden, because they would do
 * nothing.
 */
final class Snippet_View
{
    public function code(string $id, string $file, string $text, string $secret, string $copy_label, bool $wrap = false): void
    {
        $has_secret = '' !== $secret && str_contains($text, $secret);
        ?>
        <div class="wpmcp-code" data-wpmcp-code>
            <div class="wpmcp-code__bar">
                <span class="wpmcp-code__file"><?php echo esc_html($file); ?></span>
                <span class="wpmcp-code__tools">
                    <?php if ($has_secret) : ?>
                        <button type="button" class="button button-small wpmcp-reveal" data-wpmcp-reveal aria-pressed="false" hidden><?php echo esc_html__('Show password', 'wpmcp'); ?></button>
                    <?php endif; ?>
                    <button type="button" class="button button-small wpmcp-copy" data-copy-target="<?php echo esc_attr($id); ?>" aria-label="<?php echo esc_attr($copy_label); ?>" hidden><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="wpmcp-copy__label"><?php echo esc_html__('Copy', 'wpmcp'); ?></span></button>
                </span>
            </div>
            <pre class="<?php echo esc_attr($wrap ? 'is-wrapped' : ''); ?>" id="<?php echo esc_attr($id); ?>" tabindex="0" role="region" aria-label="<?php echo esc_attr($file); ?>"><?php echo wp_kses($this->marked($text, $secret), [ 'span' => [ 'class' => true ] ]); ?></pre>
        </div>
        <?php if ($has_secret) : ?>
            <p class="wpmcp-code__foot" data-wpmcp-secret-note hidden><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php echo esc_html__('The password is hidden on screen. Copy still copies all of it.', 'wpmcp'); ?></p>
        <?php endif; ?>
        <?php
    }

    public function copy_row(string $id, string $text, string $copy_label): void
    {
        ?>
        <div class="wpmcp-copyrow">
            <code id="<?php echo esc_attr($id); ?>"><?php echo esc_html($text); ?></code>
            <button type="button" class="button button-small wpmcp-copy" data-copy-target="<?php echo esc_attr($id); ?>" aria-label="<?php echo esc_attr($copy_label); ?>" hidden><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="wpmcp-copy__label"><?php echo esc_html__('Copy', 'wpmcp'); ?></span></button>
        </div>
        <?php
    }

    /** The escaped text with each occurrence of the secret wrapped for masking. */
    private function marked(string $text, string $secret): string
    {
        $html = esc_html($text);
        if ('' === $secret) {
            return $html;
        }

        $escaped = esc_html($secret);

        return str_replace($escaped, '<span class="wpmcp-secret">' . $escaped . '</span>', $html);
    }
}
