<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\InvalidItemException;

/**
 * One feed item, validated and normalised (cases 7 to 12, 17, 19).
 * Immutable: the with* methods return a copy.
 */
final class Item
{
    public const MAX_BODY_BYTES = 1048576;
    public const MAX_FIELD_CHARS = 2000;

    /**
     * @param list<string> $bylines
     * @param list<string> $truncated
     */
    private function __construct(
        private string $url,
        private string $title,
        private string $bodyHtml,
        private string $bodyText,
        private string $sourceName,
        private array $bylines,
        private int|string|null $published,
        private int|string|null $updated,
        private string $guid,
        private string $feedUrl,
        private string $sourceKey,
        private ?string $language,
        private string $feedTitle,
        private array $truncated,
        private bool $sourceDerived = false,
    ) {
    }

    /**
     * Build an item from the array of section 5.4.
     *
     * @param array<mixed> $data
     * @param bool $allowEmptyTitle true when page fetching may still supply a title
     * @throws InvalidItemException with reason bad_url or no_title
     */
    public static function fromArray(array $data, bool $allowEmptyTitle = false): self
    {
        $truncated = [];
        $field = static function (string $key) use ($data, &$truncated): string {
            $value = $data[$key] ?? '';
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                return '';
            }
            $value = Text::decodeEscapes($value);
            if (mb_strlen($value, 'UTF-8') > self::MAX_FIELD_CHARS) {
                $truncated[] = $key;
                $value = mb_substr($value, 0, self::MAX_FIELD_CHARS, 'UTF-8');
            }

            return $value;
        };

        $guid = trim($field('guid'));
        $url = Url::clean($field('url'));
        if (!Url::isValid($url)) {
            $guidUrl = Url::clean($guid);
            if (!Url::isValid($guidUrl)) {
                throw new InvalidItemException('bad_url', 'The item has no valid http or https URL.');
            }
            $url = $guidUrl;
        }

        $title = Text::plainLine($field('title'));
        if (!self::hasWords($title)) {
            if (!$allowEmptyTitle) {
                throw new InvalidItemException('no_title', 'The item has no usable title.');
            }
            $title = '';
        }

        $body = $data['body'] ?? '';
        $body = is_string($body) ? Text::decodeEscapes($body) : '';
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $truncated[] = 'body';
            $body = mb_strcut($body, 0, self::MAX_BODY_BYTES, 'UTF-8');
        }

        $feedTitle = Text::plainLine($field('feed_title'));
        $sourceName = Text::plainLine($field('source_name'));
        $derived = false;
        if ($sourceName === '') {
            $derived = $feedTitle === '';
            $sourceName = $feedTitle !== '' ? $feedTitle : Url::bareHost(Url::host($url));
        }

        $byline = $data['byline'] ?? null;
        if (is_array($byline)) {
            $byline = array_values(array_filter($byline, 'is_string'));
        } elseif (!is_string($byline)) {
            $byline = null;
        }

        return new self(
            $url,
            $title,
            $body,
            Text::htmlToText($body),
            $sourceName,
            Byline::normalise($byline, $sourceName),
            self::timeValue($data['published'] ?? null),
            self::timeValue($data['updated'] ?? null),
            $guid,
            Url::isValid(Url::clean($field('feed_url'))) ? Url::clean($field('feed_url')) : '',
            substr((string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', $field('source_key')), 0, 191),
            self::normaliseLanguage($field('language')),
            $feedTitle,
            $truncated,
            $derived,
        );
    }

    /**
     * A BCP 47 tag with a lower-case primary subtag, or null.
     */
    public static function normaliseLanguage(string $tag): ?string
    {
        $tag = str_replace('_', '-', trim($tag));
        if ($tag === '' || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
            return null;
        }
        $parts = explode('-', $tag);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1]) && strlen($parts[1]) === 2) {
            $parts[1] = strtoupper($parts[1]);
        }

        return implode('-', $parts);
    }

    private static function hasWords(string $title): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $title) === 1;
    }

    private static function timeValue(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return mb_substr(trim($value), 0, 100, 'UTF-8');
        }

        return null;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function bodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function bodyText(): string
    {
        return $this->bodyText;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    /** @return list<string> */
    public function bylines(): array
    {
        return $this->bylines;
    }

    public function published(): int|string|null
    {
        return $this->published;
    }

    public function updated(): int|string|null
    {
        return $this->updated;
    }

    public function guid(): string
    {
        return $this->guid;
    }

    public function feedUrl(): string
    {
        return $this->feedUrl;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    /** True when the source name came from the URL host (case 11). */
    public function sourceDerived(): bool
    {
        return $this->sourceDerived;
    }

    public function feedTitle(): string
    {
        return $this->feedTitle;
    }

    /** @return list<string> fields cut to the size limits */
    public function truncated(): array
    {
        return $this->truncated;
    }

    /**
     * SHA-256 of the body text with all white space removed, so a white
     * space change is not an update (case 15).
     */
    public function bodyHash(): string
    {
        return hash('sha256', (string) preg_replace('/\s+/u', '', $this->bodyText));
    }

    public function withUrl(string $url): self
    {
        if (!Url::isValid($url)) {
            return $this;
        }
        $clone = clone $this;
        $clone->url = $url;
        if ($this->sourceDerived) {
            $clone->sourceName = Url::bareHost(Url::host($url));
        }

        return $clone;
    }

    public function withTitle(string $title): self
    {
        $clone = clone $this;
        $clone->title = Text::plainLine($title);

        return $clone;
    }

    public function withLanguage(?string $language): self
    {
        $clone = clone $this;
        $clone->language = $language === null ? null : self::normaliseLanguage($language);

        return $clone;
    }

    public function withBody(string $html): self
    {
        $clone = clone $this;
        $clone->bodyHtml = $html;
        $clone->bodyText = Text::htmlToText($html);

        return $clone;
    }

    /**
     * The array form, as stored with the record and rebuilt by fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'body' => $this->bodyHtml,
            'source_name' => $this->sourceDerived ? '' : $this->sourceName,
            'byline' => $this->bylines,
            'published' => $this->published,
            'updated' => $this->updated,
            'guid' => $this->guid,
            'feed_url' => $this->feedUrl,
            'source_key' => $this->sourceKey,
            'language' => $this->language ?? '',
            'feed_title' => $this->feedTitle,
        ];
    }
}
