<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * What the source page says about itself: head metadata, JSON-LD and the
 * first <time> inside the first <article>. The page body is never kept.
 */
final class PageMeta
{
    private const ARTICLE_TYPES = ['article', 'newsarticle', 'blogposting', 'reportagenewsarticle', 'analysisnewsarticle', 'opinionnewsarticle', 'reviewnewsarticle', 'backgroundnewsarticle'];

    /**
     * @param list<string> $types JSON-LD @type values, as given
     * @param list<string> $robots robots directives, lower case
     */
    public function __construct(
        public readonly string $finalUrl = '',
        public readonly string $title = '',
        public readonly string $language = '',
        public readonly string $canonical = '',
        public readonly string $publishedTime = '',
        public readonly string $modifiedTime = '',
        public readonly array $types = [],
        public readonly ?bool $accessibleForFree = null,
        public readonly string $articleBody = '',
        public readonly array $robots = [],
    ) {
    }

    /**
     * Parse the head, every JSON-LD block, and the start of the first <article>.
     *
     * @param string $robotsHeader the X-Robots-Tag header value, if any
     */
    public static function fromHtml(string $html, string $finalUrl = '', string $robotsHeader = ''): self
    {
        $headEnd = stripos($html, '</head>');
        $head = $headEnd === false ? substr($html, 0, 200000) : substr($html, 0, $headEnd);

        $meta = [];
        if (preg_match_all('/<meta\b[^>]*>/is', $head, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                $attrs = self::attributes($tag);
                $key = strtolower($attrs['property'] ?? $attrs['name'] ?? $attrs['itemprop'] ?? '');
                if ($key !== '' && isset($attrs['content']) && !isset($meta[$key])) {
                    $meta[$key] = Text::decodeEntities($attrs['content']);
                }
            }
        }

        $title = trim($meta['og:title'] ?? '');
        if ($title === '' && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $head, $m) === 1) {
            $title = Text::plainLine($m[1]);
        }

        $language = '';
        if (preg_match('/<html\b[^>]*>/is', $html, $m) === 1) {
            $language = self::attributes($m[0])['lang'] ?? '';
        }

        $canonical = '';
        if (preg_match_all('/<link\b[^>]*>/is', $head, $links) > 0) {
            foreach ($links[0] as $link) {
                $attrs = self::attributes($link);
                if (in_array('canonical', preg_split('/\s+/', strtolower($attrs['rel'] ?? '')) ?: [], true)) {
                    $canonical = Url::clean($attrs['href'] ?? '');
                    break;
                }
            }
        }

        $robots = [];
        foreach ([$meta['robots'] ?? '', $robotsHeader] as $value) {
            foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $directive) {
                if ($directive !== '' && !in_array($directive, $robots, true)) {
                    $robots[] = $directive;
                }
            }
        }

        $types = [];
        $published = '';
        $modified = '';
        $free = null;
        $articleBody = '';
        if (preg_match_all('/<script\b[^>]*type\s*=\s*["\']?application\/ld\+json["\']?[^>]*>(.*?)<\/script>/is', $html, $blocks) > 0) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(trim($json), true);
                if (!is_array($data)) {
                    continue;
                }
                foreach (self::nodes($data) as $node) {
                    $nodeTypes = array_values(array_filter((array) ($node['@type'] ?? []), 'is_string'));
                    foreach ($nodeTypes as $t) {
                        $types[] = $t;
                    }
                    if (array_key_exists('isAccessibleForFree', $node)) {
                        $flag = $node['isAccessibleForFree'];
                        $isFree = !($flag === false || (is_string($flag) && in_array(strtolower($flag), ['false', 'no', '0'], true)));
                        $free = $free === false ? false : $isFree;
                    }
                    $isArticle = array_intersect(array_map('strtolower', $nodeTypes), self::ARTICLE_TYPES) !== [];
                    if (!$isArticle) {
                        continue;
                    }
                    if ($published === '' && is_string($node['datePublished'] ?? null)) {
                        $published = $node['datePublished'];
                    }
                    if ($modified === '' && is_string($node['dateModified'] ?? null)) {
                        $modified = $node['dateModified'];
                    }
                    if ($articleBody === '' && is_string($node['articleBody'] ?? null)) {
                        $articleBody = $node['articleBody'];
                    }
                }
            }
        }

        if ($published === '' && isset($meta['article:published_time'])) {
            $published = $meta['article:published_time'];
        }
        if ($modified === '' && isset($meta['article:modified_time'])) {
            $modified = $meta['article:modified_time'];
        }
        if ($published === '') {
            $start = stripos($html, '<article');
            if ($start !== false) {
                $region = substr($html, $start, 20000);
                if (preg_match('/<time\b[^>]*\bdatetime\s*=\s*["\']([^"\']+)["\']/i', $region, $m) === 1) {
                    $published = Text::decodeEntities($m[1]);
                }
            }
        }

        return new self(
            $finalUrl,
            Text::plainLine($title),
            $language,
            $canonical,
            trim($published),
            trim($modified),
            array_values(array_unique($types)),
            $free,
            $articleBody,
            $robots,
        );
    }

    /**
     * True when the page asks not to be quoted (case 69).
     */
    public function forbidsSnippets(): bool
    {
        return in_array('nosnippet', $this->robots, true) || in_array('noarchive', $this->robots, true) || in_array('none', $this->robots, true);
    }

    /**
     * True when some JSON-LD node is an article type.
     */
    public function hasArticleType(): bool
    {
        return array_intersect(array_map('strtolower', $this->types), self::ARTICLE_TYPES) !== [];
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function nodes(array $data): array
    {
        $out = [];
        $stack = [$data];
        $guard = 0;
        while ($stack !== [] && $guard++ < 500) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            if (array_is_list($node)) {
                foreach ($node as $child) {
                    $stack[] = $child;
                }
                continue;
            }
            if (isset($node['@type'])) {
                $out[] = $node;
            }
            foreach (['@graph', 'mainEntity', 'mainEntityOfPage', 'hasPart', 'video'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $stack[] = $node[$key];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        $attrs = [];
        if (preg_match_all('/([A-Za-z_:][A-Za-z0-9_:.\-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/s', $tag, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $value = ($match[3] ?? '') !== '' ? ($match[3] ?? '') : (($match[4] ?? '') !== '' ? $match[4] : ($match[5] ?? ''));
                $attrs[strtolower($match[1])] = $value;
            }
        }

        return $attrs;
    }
}
