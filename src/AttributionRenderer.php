<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Renders the attribution block (case 45 to 47).
 */
final class AttributionRenderer
{
    public const BLOCK = 'feed-item-brief/attribution';
    public const WRAPPER = 'feed-item-brief-attribution';

    private static bool $warned = false;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * The plugin's own markup, without the restyling filter. Used for the
     * static fallback saved in the post.
     */
    public function own(Attribution $a): string
    {
        $parts = [];
        $parts[] = '<p class="feed-item-brief-source">' . esc_html__('Source:', 'feed-item-brief')
            . ' <a href="' . esc_url($a->sourceUrl) . '" rel="nofollow noopener">' . esc_html($a->sourceName) . '</a></p>';
        if ($a->bylines !== []) {
            /* translators: %s: one or more author names. */
            $by = sprintf(__('By %s', 'feed-item-brief'), Byline::join($a->bylines, __('and', 'feed-item-brief')));
            $parts[] = '<p class="feed-item-brief-byline">' . esc_html($by) . '</p>';
        }
        if ($a->publishedAt > 0) {
            $format = trim(self::option('date_format', 'F j, Y') . ' ' . self::option('time_format', 'g:i a'));
            $parts[] = '<p class="feed-item-brief-published">' . esc_html__('Published', 'feed-item-brief')
                . ' <time datetime="' . esc_attr(gmdate('Y-m-d\TH:i:s\Z', $a->publishedAt)) . '">'
                . esc_html((string) wp_date($format, $a->publishedAt)) . '</time></p>';
        }
        if ($a->excerptNote) {
            $parts[] = '<p class="feed-item-brief-excerpt-note">' . esc_html__('Summary based on the publicly available excerpt.', 'feed-item-brief') . '</p>';
        }
        $disclosure = $this->settings->disclosure();
        if ($disclosure !== '') {
            $parts[] = '<p class="feed-item-brief-disclosure">' . esc_html($disclosure) . '</p>';
        }

        return '<div class="wp-block-feed-item-brief-attribution ' . self::WRAPPER . '">' . implode('', $parts) . '</div>';
    }

    /**
     * The markup shown to readers: the restyling filter's output when it
     * still links the source, else the plugin's own.
     */
    public function render(Attribution $a): string
    {
        $own = $this->own($a);
        $filtered = apply_filters('feed_item_brief_attribution_html', $own, $a);
        if (!is_string($filtered) || $filtered === $own) {
            return $own;
        }
        if (!self::linksSource($filtered, $a)) {
            if (!self::$warned) {
                self::$warned = true;
                _doing_it_wrong(
                    'feed_item_brief_attribution_html',
                    esc_html__('The filtered attribution must keep a link to the source URL whose text contains the source name. The plugin\'s own block was used.', 'feed-item-brief'),
                    '0.1.0'
                );
            }

            return $own;
        }
        if (!str_contains($filtered, self::WRAPPER)) {
            $filtered = '<div class="' . self::WRAPPER . '">' . $filtered . '</div>';
        }

        return $filtered;
    }

    /**
     * Block markup saved in post content: a complete static fallback that
     * reads correctly without the plugin, locked against removal in the editor.
     */
    public function blockMarkup(Attribution $a): string
    {
        return '<!-- wp:' . self::BLOCK . ' {"lock":{"move":false,"remove":true}} -->' . "\n"
            . $this->own($a) . "\n"
            . '<!-- /wp:' . self::BLOCK . ' -->';
    }

    /**
     * True when $html has an anchor whose href is the source URL and whose
     * text contains the source name.
     */
    public static function linksSource(string $html, Attribution $a): bool
    {
        $doc = self::load($html);

        return $doc !== null && self::anchorsLink($doc->getElementsByTagName('a'), $a);
    }

    /**
     * True when $html holds the attribution wrapper and, inside it, the link
     * to the source (case 46). A wrapper class typed into the text, or a
     * wrapper whose link was edited out, does not count.
     */
    public static function containsBlock(string $html, Attribution $a): bool
    {
        if (!str_contains($html, self::WRAPPER)) {
            return false;
        }
        $doc = self::load($html);
        if ($doc === null) {
            return false;
        }
        $xpath = new \DOMXPath($doc);
        $anchors = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . self::WRAPPER . ' ")]//a');

        return $anchors instanceof \DOMNodeList && self::anchorsLink($anchors, $a);
    }

    private static function load(string $html): ?\DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded === false ? null : $doc;
    }

    /**
     * @param iterable<mixed> $anchors
     */
    private static function anchorsLink(iterable $anchors, Attribution $a): bool
    {
        $want = [$a->sourceUrl, html_entity_decode(esc_url($a->sourceUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        $name = mb_strtolower(trim($a->sourceName), 'UTF-8');
        if ($name === '') {
            return false;
        }
        foreach ($anchors as $anchor) {
            if (!$anchor instanceof \DOMElement) {
                continue;
            }
            $href = trim($anchor->getAttribute('href'));
            if (!in_array($href, $want, true)) {
                continue;
            }
            $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? ''), 'UTF-8');
            if (str_contains($text, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function option(string $name, string $default): string
    {
        $value = get_option($name, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** For tests: forget the once-per-request warning. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
