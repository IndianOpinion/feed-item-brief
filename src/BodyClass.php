<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The outcome of body classification (section 4.4).
 */
final class BodyClass
{
    public const NOT_ARTICLE = 'not_article';
    public const SPONSORED = 'sponsored';
    public const NO_BODY = 'no_body';
    public const PAYWALLED = 'paywalled';
    public const TEASER = 'teaser';
    public const SHORT = 'short';
    public const FULL = 'full';
    public const LINK_ONLY = 'link_only';

    public const SKIP = 'skip';
    public const SUMMARISE = 'summarise';

    public function __construct(
        public readonly string $kind,
        public readonly string $action,
        public readonly string $text = '',
        public readonly int $minWords = 0,
        public readonly int $maxWords = 0,
        public readonly bool $excerptNote = false,
        public readonly string $reason = '',
        public readonly int $words = 0,
    ) {
    }

    public static function skip(string $kind, string $reason = ''): self
    {
        return new self($kind, self::SKIP, '', 0, 0, false, $reason !== '' ? $reason : $kind);
    }

    public static function linkOnly(string $text, string $reason): self
    {
        return new self(self::LINK_ONLY, self::LINK_ONLY, $text, 0, 0, false, $reason, Text::wordCount($text));
    }

    public function isSkip(): bool
    {
        return $this->action === self::SKIP;
    }

    public function isLinkOnly(): bool
    {
        return $this->action === self::LINK_ONLY;
    }

    public function isSummarise(): bool
    {
        return $this->action === self::SUMMARISE;
    }
}
