<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Post meta keys on drafts (section 6). Protected keys (leading underscore).
 */
final class Meta
{
    public const RECORD = '_feed_item_brief_record';
    public const SOURCE_URL = '_feed_item_brief_source_url';
    public const SOURCE_NAME = '_feed_item_brief_source_name';
    public const BYLINE = '_feed_item_brief_byline';
    public const RESOLVED = '_feed_item_brief_resolved';
    public const CLAIMED = '_feed_item_brief_claimed';
    public const DATE_REASON = '_feed_item_brief_date_reason';
    public const BODY_CLASS = '_feed_item_brief_body_class';
    public const MODE = '_feed_item_brief_mode';
    public const MODEL = '_feed_item_brief_model';
    public const CALL_TIME = '_feed_item_brief_call_time';
    public const FLAGS = '_feed_item_brief_flags';
    public const UPDATES = '_feed_item_brief_updates';
    public const CORRECTIONS = '_feed_item_brief_corrections';
    public const EXCERPT_NOTE = '_feed_item_brief_excerpt_note';

    /**
     * Key => type, for register_post_meta. All read-only in REST.
     *
     * @return array<string, string>
     */
    public static function registered(): array
    {
        return [
            self::RECORD => 'integer',
            self::SOURCE_URL => 'string',
            self::SOURCE_NAME => 'string',
            self::BYLINE => 'string',
            self::RESOLVED => 'integer',
            self::CLAIMED => 'string',
            self::DATE_REASON => 'string',
            self::BODY_CLASS => 'string',
            self::MODE => 'string',
            self::MODEL => 'string',
            self::CALL_TIME => 'integer',
            self::FLAGS => 'string',
            self::UPDATES => 'string',
            self::CORRECTIONS => 'string',
            self::EXCERPT_NOTE => 'boolean',
        ];
    }

    /**
     * Decode a JSON list stored in meta.
     *
     * @return list<mixed>
     */
    public static function jsonList(int $postId, string $key): array
    {
        $raw = get_post_meta($postId, $key, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param list<mixed> $list
     */
    public static function setJsonList(int $postId, string $key, array $list): void
    {
        update_post_meta($postId, $key, wp_slash((string) wp_json_encode(array_values($list))));
    }
}
