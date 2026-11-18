<?php

/**
 * Public functions (section 5.5).
 */

declare(strict_types=1);

use IndianOpinion\FeedItemBrief\Attribution;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Submission;

if (!function_exists('feed_item_brief_submit')) {
    /**
     * Submit one item from an importer. Returns at once; never throws.
     *
     * @param array<mixed> $item
     */
    function feed_item_brief_submit(array $item, string $adapterId): Submission
    {
        return Plugin::instance()->submit($item, $adapterId);
    }
}

if (!function_exists('feed_item_brief_is_enabled')) {
    function feed_item_brief_is_enabled(): bool
    {
        return Plugin::instance()->settings->isEnabled();
    }
}

if (!function_exists('feed_item_brief_calls_today')) {
    function feed_item_brief_calls_today(): int
    {
        return Plugin::instance()->gate->callsToday();
    }
}

if (!function_exists('feed_item_brief_cap')) {
    function feed_item_brief_cap(): int
    {
        return Plugin::instance()->settings->cap();
    }
}

if (!function_exists('feed_item_brief_attribution')) {
    function feed_item_brief_attribution(int $postId): ?Attribution
    {
        return Attribution::fromPost($postId);
    }
}
