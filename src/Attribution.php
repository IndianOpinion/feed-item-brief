<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The values the attribution block shows. Always from stored fields, never
 * from model output (case 43).
 */
final class Attribution
{
    /**
     * @param list<string> $bylines
     */
    public function __construct(
        public readonly string $sourceUrl,
        public readonly string $sourceName,
        public readonly array $bylines,
        public readonly int $publishedAt,
        public readonly bool $excerptNote = false,
    ) {
    }

    /**
     * From a post's meta, or null when the post has no valid attribution.
     */
    public static function fromPost(int $postId): ?self
    {
        if ($postId <= 0) {
            return null;
        }
        $url = get_post_meta($postId, Meta::SOURCE_URL, true);
        if (!is_string($url) || !Url::isValid($url)) {
            return null;
        }
        $name = get_post_meta($postId, Meta::SOURCE_NAME, true);
        $name = is_string($name) && trim($name) !== '' ? $name : Url::bareHost(Url::host($url));
        $bylines = array_values(array_filter(Meta::jsonList($postId, Meta::BYLINE), 'is_string'));
        $resolved = get_post_meta($postId, Meta::RESOLVED, true);

        return new self(
            $url,
            $name,
            $bylines,
            is_numeric($resolved) ? (int) $resolved : 0,
            (bool) get_post_meta($postId, Meta::EXCERPT_NOTE, true),
        );
    }
}
