<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Decides whether an item can be summarised honestly (section 4.4).
 * The first match wins.
 */
final class Classifier
{
    public const NOT_ARTICLE_PATTERNS = [
        '#/(videos?|watch|tv)(/|$)#i',
        '#/(gallery|galleries|photos?|photo-?gallery|picture-?gallery|slideshows?|in-pictures)(/|$)#i',
        '#/(podcasts?)/?$#i',
        '#/(live|live-?blog|live-?updates)/?$#i',
        '#/(topics?|tags?|category|categories)/#i',
        '#/(authors?|profiles?|contributors?)/#i',
        '#/search(/|$|\?)#i',
    ];

    public const NOT_ARTICLE_TYPES = [
        'videoobject', 'imagegallery', 'mediagallery', 'imageobject', 'podcastseries',
        'collectionpage', 'profilepage', 'searchresultspage', 'liveblogposting',
    ];

    public const SPONSORED_TYPES = ['advertisement', 'advertisercontentarticle'];

    public const MIN_WORDS = 15;
    public const FULL_WORDS = 120;
    public const TEASER_MIN_WORDS = 40;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function classify(Item $item, ?PageMeta $page = null): BodyClass
    {
        $path = (string) parse_url($item->url(), PHP_URL_PATH);

        if ($this->notArticle($path, $page)) {
            return BodyClass::skip(BodyClass::NOT_ARTICLE);
        }
        if ($this->sponsored($path, $item, $page)) {
            return BodyClass::skip(BodyClass::SPONSORED);
        }

        $text = $item->bodyText();
        $words = Text::wordCount($text);
        $min = max(1, (int) apply_filters('feed_item_brief_min_words', self::MIN_WORDS));

        if ($page !== null && $page->forbidsSnippets()) {
            return BodyClass::linkOnly(Text::firstWords($text, 60), 'robots_nosnippet');
        }

        if ($words < $min) {
            $extracted = apply_filters('feed_item_brief_fetch_body', null, $item);
            $fromExtractor = is_string($extracted) ? Text::htmlToText($extracted) : '';
            $fromPage = $page !== null ? Text::htmlToText($page->articleBody) : '';
            if (Text::wordCount($fromExtractor) >= $min) {
                $text = $fromExtractor;
            } elseif (Text::wordCount($fromPage) >= $min) {
                $text = $fromPage;
            } else {
                return $this->settings->noBodyMode() === 'link_only'
                    ? BodyClass::linkOnly(Text::firstWords($text, 60), BodyClass::NO_BODY)
                    : BodyClass::skip(BodyClass::NO_BODY);
            }
            $words = Text::wordCount($text);
        }

        $paywallReason = $this->paywallReason($text, $words, $page);
        if ($paywallReason !== null) {
            $teaser = $this->stripPaywallTail($text);
            $mode = $this->settings->paywallMode();
            if ($mode === 'skip') {
                return BodyClass::skip(BodyClass::PAYWALLED, $paywallReason);
            }
            $teaserWords = Text::wordCount($teaser);
            if ($mode === 'teaser' && $teaserWords >= self::TEASER_MIN_WORDS) {
                [$lo, $hi] = $this->length('teaser', 40, 80);

                return new BodyClass(BodyClass::TEASER, BodyClass::SUMMARISE, $teaser, $lo, $hi, true, $paywallReason, $teaserWords);
            }

            return BodyClass::linkOnly(Text::firstWords($teaser, 60), $paywallReason);
        }

        if ($words < self::FULL_WORDS) {
            [$lo, $hi] = $this->length('short', 40, 80);

            return new BodyClass(BodyClass::SHORT, BodyClass::SUMMARISE, $text, $lo, $hi, false, 'short', $words);
        }
        [$lo, $hi] = $this->length('full', 100, 170);

        return new BodyClass(BodyClass::FULL, BodyClass::SUMMARISE, $text, $lo, $hi, false, 'full', $words);
    }

    /**
     * @return array{int, int}
     */
    private function length(string $mode, int $min, int $max): array
    {
        $range = apply_filters('feed_item_brief_length', ['min' => $min, 'max' => $max], $mode);
        if (is_array($range) && isset($range['min'], $range['max']) && is_numeric($range['min']) && is_numeric($range['max'])) {
            $lo = max(1, (int) $range['min']);
            $hi = max($lo, (int) $range['max']);

            return [$lo, $hi];
        }

        return [$min, $max];
    }

    private function notArticle(string $path, ?PageMeta $page): bool
    {
        $patterns = apply_filters('feed_item_brief_not_article_patterns', self::NOT_ARTICLE_PATTERNS);
        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (is_string($pattern) && $pattern !== '' && self::safeMatch($pattern, $path)) {
                return true;
            }
        }
        if ($page !== null && !$page->hasArticleType()) {
            $types = apply_filters('feed_item_brief_not_article_types', self::NOT_ARTICLE_TYPES);
            $types = array_map('strtolower', array_filter(is_array($types) ? $types : [], 'is_string'));
            if (array_intersect(array_map('strtolower', $page->types), $types) !== []) {
                return true;
            }
        }

        return false;
    }

    private function sponsored(string $path, Item $item, ?PageMeta $page): bool
    {
        if ($page !== null && array_intersect(array_map('strtolower', $page->types), self::SPONSORED_TYPES) !== []) {
            return true;
        }
        $phrases = apply_filters('feed_item_brief_sponsored_phrases', $this->settings->list('sponsored_phrases'));
        $haystacks = [
            str_replace(['-', '_', '/'], ' ', rawurldecode($path)),
            $item->title(),
            mb_substr($item->bodyText(), 0, 400, 'UTF-8'),
        ];
        foreach (is_array($phrases) ? $phrases : [] as $phrase) {
            if (!is_string($phrase) || trim($phrase) === '') {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if (self::containsPhrase($haystack, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function paywallReason(string $text, int $words, ?PageMeta $page): ?string
    {
        if ($page !== null && $page->accessibleForFree === false) {
            return 'paywall_jsonld';
        }
        $sentences = Text::sentences($text);
        $last = $sentences === [] ? '' : $sentences[count($sentences) - 1];
        foreach ($this->paywallPhrases() as $phrase) {
            if (self::containsPhrase($last, $phrase)) {
                return 'paywall_phrase';
            }
        }
        if ($words < self::FULL_WORDS && preg_match('/(\.\.\.|\x{2026}|\[\s*(\.\.\.|\x{2026})\s*\]|\bread more)[\s\p{P}]*$/iu', $text) === 1) {
            return 'paywall_cut';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function paywallPhrases(): array
    {
        $phrases = apply_filters('feed_item_brief_paywall_phrases', $this->settings->list('paywall_phrases'));

        return array_values(array_filter(is_array($phrases) ? $phrases : [], static fn ($p): bool => is_string($p) && trim($p) !== ''));
    }

    /**
     * The teaser without the closing paywall sentence, ellipsis or "Read more".
     */
    private function stripPaywallTail(string $text): string
    {
        $sentences = Text::sentences($text);
        if ($sentences !== []) {
            $last = $sentences[count($sentences) - 1];
            foreach ($this->paywallPhrases() as $phrase) {
                if (self::containsPhrase($last, $phrase)) {
                    array_pop($sentences);
                    break;
                }
            }
        }
        $text = implode(' ', $sentences);
        $text = (string) preg_replace('/[\s,;:]*(\[\s*(\.\.\.|\x{2026})\s*\]|\bread more\b[\s\p{P}]*|\.\.\.|\x{2026})\s*$/iu', '', $text);

        return trim($text);
    }

    public static function containsPhrase(string $haystack, string $phrase): bool
    {
        $quoted = preg_quote(trim($phrase), '/');
        $quoted = (string) preg_replace('/\\\\?\s+/', '\s+', $quoted);

        return preg_match('/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu', $haystack) === 1;
    }

    private static function safeMatch(string $pattern, string $subject): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, $subject) === 1;
        } finally {
            restore_error_handler();
        }
    }
}
