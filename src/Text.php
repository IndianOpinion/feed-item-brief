<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Text helpers: escape decoding, HTML to plain text, word counts and
 * sentence-safe cutting. Pure functions, no WordPress needed.
 */
final class Text
{
    /** Elements removed with their content before text is read. */
    private const DROP = [
        'script', 'style', 'noscript', 'iframe', 'form', 'figcaption', 'button',
        'svg', 'template', 'object', 'embed', 'select', 'textarea', 'input', 'video', 'audio',
    ];

    /** Elements that end a paragraph. */
    private const BLOCK = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol', 'blockquote',
        'section', 'article', 'header', 'footer', 'pre', 'table', 'tr', 'figure', 'hr',
        'main', 'aside', 'dl', 'dt', 'dd', 'address', 'br',
    ];

    /** Class or id words that mark share widgets and sign-up boxes. */
    private const WIDGET = '/(^|[\s_-])(share|sharing|social|sharedaddy|share-buttons|newsletter-signup)([\s_-]|$)/i';

    /**
     * Decode literal escape sequences that leak from upstream JSON:
     * a backslash-u code (with surrogate pairs), and backslash n, r, t.
     */
    public static function decodeEscapes(string $text): string
    {
        if (!str_contains($text, '\\')) {
            return $text;
        }
        $text = (string) preg_replace_callback(
            '/\\\\u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})|\\\\u([0-9a-fA-F]{4})/',
            static function (array $m): string {
                if (($m[3] ?? '') !== '') {
                    $code = (int) hexdec($m[3]);
                    if ($code >= 0xD800 && $code <= 0xDFFF) {
                        return '';
                    }
                } else {
                    $high = (int) hexdec($m[1]);
                    $low = (int) hexdec($m[2]);
                    $code = 0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00);
                }
                return (string) mb_chr($code, 'UTF-8');
            },
            $text
        );

        return str_replace(['\\r\\n', '\\n', '\\r', '\\t'], ["\n", "\n", "\n", ' '], $text);
    }

    public static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip tags, decode entities and collapse all white space to single spaces.
     */
    public static function plainLine(string $text): string
    {
        $text = self::decodeEntities(strip_tags($text));
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Reduce HTML (or plain text) to paragraphs separated by one blank line.
     */
    public static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (!str_contains($html, '<')) {
            return self::normaliseParagraphs(self::decodeEntities($html));
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return self::normaliseParagraphs(self::decodeEntities(strip_tags($html)));
        }
        $out = '';
        foreach ($doc->childNodes as $child) {
            $out .= self::walk($child);
        }

        return self::normaliseParagraphs($out);
    }

    private static function walk(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->data);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROP, true)) {
            return '';
        }
        $marker = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
        if (trim($marker) !== '' && preg_match(self::WIDGET, $marker) === 1) {
            return '';
        }
        if ($name === 'br') {
            return "\n\n";
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::walk($child);
        }
        if (in_array($name, self::BLOCK, true)) {
            return "\n\n" . $inner . "\n\n";
        }

        return $inner;
    }

    /**
     * Collapse white space inside paragraphs; keep one blank line between them.
     */
    public static function normaliseParagraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/\n\s*\n/u', $text) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $part));
            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return implode("\n\n", $clean);
    }

    /**
     * Words in any script. A run of CJK characters counts one word per two
     * characters, rounded up.
     */
    public static function wordCount(string $text): int
    {
        $count = 0;
        if (preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}]+|[\p{L}\p{M}\p{N}]+(?:[\'\x{2019}\-][\p{L}\p{M}\p{N}]+)*/u', $text, $m) > 0) {
            foreach ($m[0] as $run) {
                if (preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}]+$/u', $run) === 1) {
                    $count += (int) ceil(mb_strlen($run, 'UTF-8') / 2);
                } else {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Split text into sentences, keeping the end punctuation.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?\x{3002}\x{0964}\x{061F}])["\x{201D}\x{2019})\]]*\s+/u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * The longest run of whole sentences that fits in $maxChars. When even
     * the first sentence is longer, it is cut at a word boundary and gets an
     * ellipsis.
     */
    public static function cutAtSentence(string $text, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }
        $out = '';
        foreach (self::sentences($text) as $sentence) {
            $next = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (mb_strlen($next, 'UTF-8') > $maxChars) {
                break;
            }
            $out = $next;
        }
        if ($out !== '') {
            return $out;
        }
        $cut = mb_substr($text, 0, max(1, $maxChars - 1), 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }

        return rtrim($cut, " ,;:") . "\u{2026}";
    }

    /**
     * Up to $maxWords words, cut back to the last sentence end when there is one.
     */
    public static function firstWords(string $text, int $maxWords): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || self::wordCount($text) <= $maxWords) {
            return $text;
        }
        $out = '';
        foreach (self::sentences($text) as $sentence) {
            $next = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (self::wordCount($next) > $maxWords) {
                break;
            }
            $out = $next;
        }
        if ($out !== '') {
            return $out;
        }
        $words = preg_split('/\s+/u', $text) ?: [];

        return implode(' ', array_slice($words, 0, $maxWords)) . "\u{2026}";
    }

    /**
     * Lower case, straight quotes, single spaces. Used to compare quotes.
     */
    public static function forMatch(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}", "\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}", "\u{00AB}", "\u{00BB}", "\u{2033}"],
            ["'", "'", "'", "'", "'", '"', '"', '"', '"', '"', '"', '"'],
            $text
        );
        $text = str_replace(["\u{2013}", "\u{2014}"], '-', $text);

        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
    }

    /**
     * Cut a string to $max characters, reporting whether it was cut.
     */
    public static function limit(string $text, int $max, bool &$cut = false): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = true;

        return mb_strcut($text, 0, $max, 'UTF-8');
    }
}
