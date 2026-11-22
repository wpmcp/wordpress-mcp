<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads the markup Visual Composer's editor saved into post_content (issue
 * #1112) into element nodes with byte offsets, so already rendered element
 * markup can be cut and spliced without being re-serialised.
 *
 * Recorded from Visual Composer 45.16.4 (public/dist/wp.bundle.js): the
 * editor posts "<!--vcv no format-->" + normalizeHtml(innerHTML of its
 * content layout) + "<!--vcv no format-->". That is browser serialisation,
 * so every non-void element is closed and every quoted attribute is
 * double quoted. Anything this scanner cannot balance is refused
 * (\DomainException) rather than guessed at.
 *
 * @phpstan-type Open_Element array{tag: string, start: int, inner_start: int, el_id: ?string, content: bool, loose_text: bool, children: list<Visual_Composer_Markup_Node>}
 * @phpstan-type Root_Element array{children: list<Visual_Composer_Markup_Node>, loose_text: bool}
 */
final class Visual_Composer_Markup_Parser
{
    public const MARKER = '<!--vcv no format-->';

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Elements whose body the browser serialises as raw text. */
    private const RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext'];

    /** @throws \DomainException when the markup does not balance */
    public function parse(string $html): Visual_Composer_Markup
    {
        $root_start = 0;
        $root_end   = strlen($html);
        $marker     = strlen(self::MARKER);
        if (str_starts_with($html, self::MARKER) && str_ends_with($html, self::MARKER) && strlen($html) >= 2 * $marker) {
            $root_start = $marker;
            $root_end   = strlen($html) - $marker;
        }

        $root  = ['children' => [], 'loose_text' => false];
        $stack = [];
        $pos   = $root_start;
        while ($pos < $root_end) {
            $lt = strpos($html, '<', $pos);
            if (false === $lt || $lt >= $root_end) {
                $this->note_text($html, $pos, $root_end, $stack, $root);
                break;
            }
            $this->note_text($html, $pos, $lt, $stack, $root);

            if (str_starts_with(substr($html, $lt, 4), '<!--')) {
                $close = strpos($html, '-->', $lt + 4);
                if (false === $close || $close + 3 > $root_end) {
                    throw new \DomainException(esc_html('an unclosed comment'));
                }
                $pos = $close + 3;
                continue;
            }
            $next = $html[ $lt + 1 ] ?? '';
            if ('!' === $next || '?' === $next) {
                $close = strpos($html, '>', $lt);
                if (false === $close || $close >= $root_end) {
                    throw new \DomainException(esc_html('an unclosed declaration'));
                }
                $pos = $close + 1;
                continue;
            }
            if ('/' === $next) {
                if (1 !== preg_match('/\G<\/([A-Za-z][A-Za-z0-9-]*)\s*>/', $html, $m, 0, $lt)) {
                    throw new \DomainException(esc_html('an unreadable end tag'));
                }
                $open = array_pop($stack);
                if (null === $open || strtolower($m[1]) !== $open['tag']) {
                    throw new \DomainException(esc_html("an end tag </{$m[1]}> that closes nothing open"));
                }
                $this->close($open, $lt, $lt + strlen($m[0]), $stack, $root);
                $pos = $lt + strlen($m[0]);
                continue;
            }
            if (1 !== preg_match('/\G<([A-Za-z][A-Za-z0-9-]*)/', $html, $m, 0, $lt)) {
                // A lone "<" in text, as a browser would read it.
                $this->note_text($html, $lt, $lt + 1, $stack, $root);
                $pos = $lt + 1;
                continue;
            }
            $tag = strtolower($m[1]);
            $gt  = $this->tag_end($html, $lt + strlen($m[0]), $root_end);
            $raw = substr($html, $lt, $gt + 1 - $lt);
            $open = [
                'tag'         => $tag,
                'start'       => $lt,
                'inner_start' => $gt + 1,
                'el_id'       => $this->el_id($raw),
                'content'     => 1 === preg_match('/\sdata-vce-element-content(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $raw),
                'loose_text'  => false,
                'children'    => [],
            ];
            if (in_array($tag, self::VOID, true) || str_ends_with($raw, '/>')) {
                $this->close($open, $gt + 1, $gt + 1, $stack, $root);
                $pos = $gt + 1;
                continue;
            }
            if (in_array($tag, self::RAW, true)) {
                if (1 !== preg_match('/<\/' . $tag . '\s*>/i', $html, $end, PREG_OFFSET_CAPTURE, $gt + 1) || $end[0][1] >= $root_end) {
                    throw new \DomainException(esc_html("an unclosed <{$tag}>"));
                }
                $inner_end = $end[0][1];
                $this->close($open, $inner_end, $inner_end + strlen($end[0][0]), $stack, $root);
                $pos = $inner_end + strlen($end[0][0]);
                continue;
            }
            $stack[] = $open;
            $pos     = $gt + 1;
        }
        if ([] !== $stack) {
            throw new \DomainException(esc_html('an element that is never closed'));
        }

        return new Visual_Composer_Markup($html, $root_start, $root_end, $root['children'], $root['loose_text']);
    }

    /** The offset of the ">" that ends the start tag whose attributes begin at $pos (quotes respected). */
    private function tag_end(string $html, int $pos, int $limit): int
    {
        $quote = null;
        for ($i = $pos; $i < $limit; ++$i) {
            $c = $html[ $i ];
            if (null !== $quote) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ('"' === $c || "'" === $c) {
                $quote = $c;
            } elseif ('>' === $c) {
                return $i;
            }
        }

        throw new \DomainException(esc_html('an unclosed start tag'));
    }

    private function el_id(string $start_tag): ?string
    {
        if (1 !== preg_match('/\sid\s*=\s*(?:"el-([^"]*)"|\'el-([^\']*)\')/', $start_tag, $m)) {
            return null;
        }
        $id = '' !== ($m[2] ?? '') ? $m[2] : $m[1];

        return '' === $id ? null : $id;
    }

    /**
     * @param list<Open_Element> $stack
     * @param Root_Element       $root
     */
    private function note_text(string $html, int $from, int $to, array &$stack, array &$root): void
    {
        if ($to <= $from || '' === trim(substr($html, $from, $to - $from))) {
            return;
        }
        if ([] === $stack) {
            $root['loose_text'] = true;
            return;
        }
        $top               = array_pop($stack);
        $top['loose_text'] = true;
        $stack[]           = $top;
    }

    /**
     * @param Open_Element       $open
     * @param list<Open_Element> $stack
     * @param Root_Element       $root
     */
    private function close(array $open, int $inner_end, int $end, array &$stack, array &$root): void
    {
        $node = new Visual_Composer_Markup_Node(
            $open['tag'],
            $open['start'],
            $open['inner_start'],
            $inner_end,
            $end,
            $open['el_id'],
            $open['content'],
            $open['loose_text'],
            $open['children']
        );
        if ([] === $stack) {
            $root['children'][] = $node;
            return;
        }
        $top               = array_pop($stack);
        $top['children'][] = $node;
        $stack[]           = $top;
    }
}
