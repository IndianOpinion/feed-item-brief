<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Adapter;

use IndianOpinion\FeedItemBrief\Adapter;
use IndianOpinion\FeedItemBrief\Url;

/**
 * Reference adapter: WordPress core's fetch_feed() (section 4.9).
 */
final class CoreFeedAdapter implements Adapter
{
    public const ID = 'core-feed';
    public const OPTION = 'feed_item_brief_feeds';
    public const CURSOR_OPTION = 'feed_item_brief_feed_cursor';
    public const CRON = 'feed_item_brief_core_feed_poll';
    public const MAX_FEEDS = 200;
    public const FEEDS_PER_RUN = 10;
    public const ITEMS_PER_FEED = 10;
    public const CACHE_LIFETIME = 1500;
    public const FAILING_AFTER = 3;

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('WordPress core feeds', 'feed-item-brief');
    }

    public function boot(): void
    {
        add_action(self::CRON, [$this, 'poll']);
    }

    public function defaultAuthor(string $sourceKey): ?int
    {
        $feed = $this->feed($sourceKey);

        return $feed !== null && $feed['author'] > 0 ? $feed['author'] : null;
    }

    public function timezone(string $sourceKey): ?string
    {
        $feed = $this->feed($sourceKey);

        return $feed !== null && $feed['timezone'] !== '' ? $feed['timezone'] : null;
    }

    public static function keyFor(string $url): string
    {
        return substr(hash('sha256', $url), 0, 12);
    }

    /**
     * @return list<array{key: string, url: string, source_name: string, author: int, timezone: string, enabled: bool, failures: int, last_error: string}>
     */
    public static function feeds(): array
    {
        $stored = get_option(self::OPTION, []);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $feed) {
            $clean = self::sanitiseFeed(is_array($feed) ? $feed : []);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return array_slice($out, 0, self::MAX_FEEDS);
    }

    /**
     * @param array<mixed> $feed
     * @return array{key: string, url: string, source_name: string, author: int, timezone: string, enabled: bool, failures: int, last_error: string}|null
     */
    public static function sanitiseFeed(array $feed): ?array
    {
        $url = is_string($feed['url'] ?? null) ? Url::clean($feed['url']) : '';
        if (!Url::isValid($url)) {
            return null;
        }
        $timezone = is_string($feed['timezone'] ?? null) ? trim($feed['timezone']) : '';
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true) && preg_match('/^[+-]\d{2}:\d{2}$/', $timezone) !== 1) {
            $timezone = '';
        }

        return [
            'key' => self::keyFor($url),
            'url' => $url,
            'source_name' => is_string($feed['source_name'] ?? null) ? sanitize_text_field($feed['source_name']) : '',
            'author' => is_numeric($feed['author'] ?? null) ? max(0, (int) $feed['author']) : 0,
            'timezone' => $timezone,
            'enabled' => !array_key_exists('enabled', $feed) || (bool) $feed['enabled'],
            'failures' => is_numeric($feed['failures'] ?? null) ? max(0, (int) $feed['failures']) : 0,
            'last_error' => is_string($feed['last_error'] ?? null) ? $feed['last_error'] : '',
        ];
    }

    /**
     * @param list<array<mixed>> $feeds
     */
    public static function saveFeeds(array $feeds): void
    {
        $clean = [];
        $seen = [];
        foreach ($feeds as $feed) {
            $one = self::sanitiseFeed($feed);
            if ($one === null || isset($seen[$one['key']])) {
                continue;
            }
            $seen[$one['key']] = true;
            $clean[] = $one;
        }
        update_option(self::OPTION, array_slice($clean, 0, self::MAX_FEEDS), false);
    }

    /**
     * @param array<string, mixed> $feed
     */
    public static function isFailing(array $feed): bool
    {
        return (int) ($feed['failures'] ?? 0) >= self::FAILING_AFTER;
    }

    /**
     * @return array{key: string, url: string, source_name: string, author: int, timezone: string, enabled: bool, failures: int, last_error: string}|null
     */
    private function feed(string $key): ?array
    {
        foreach (self::feeds() as $feed) {
            if ($feed['key'] === $key) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Fetch up to ten enabled feeds, in rotation (case 62).
     */
    public function poll(): void
    {
        $feeds = array_values(array_filter(self::feeds(), static fn (array $f): bool => $f['enabled']));
        $count = count($feeds);
        if ($count === 0) {
            return;
        }
        $perRun = max(1, (int) apply_filters('feed_item_brief_feeds_per_run', self::FEEDS_PER_RUN));
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        $cursor = $cursor >= 0 && $cursor < $count ? $cursor : 0;
        $results = [];
        for ($i = 0; $i < min($perRun, $count); $i++) {
            $feed = $feeds[($cursor + $i) % $count];
            $results[$feed['key']] = $this->fetchOne($feed);
        }
        update_option(self::CURSOR_OPTION, ($cursor + min($perRun, $count)) % $count, false);

        $all = self::feeds();
        foreach ($all as &$feed) {
            if (!array_key_exists($feed['key'], $results)) {
                continue;
            }
            $error = $results[$feed['key']];
            if ($error === null) {
                $feed['failures'] = 0;
                $feed['last_error'] = '';
            } else {
                $feed['failures']++;
                $feed['last_error'] = $error;
            }
        }
        unset($feed);
        update_option(self::OPTION, $all, false);
    }

    /**
     * Fetch one feed and submit its items as one batch. Returns an error
     * code, or null on success.
     *
     * @param array{key: string, url: string, source_name: string, author: int, timezone: string, enabled: bool, failures: int, last_error: string} $feed
     */
    public function fetchOne(array $feed): ?string
    {
        if (!function_exists('fetch_feed') && defined('ABSPATH') && defined('WPINC')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }
        $lifetime = static fn (): int => self::CACHE_LIFETIME;
        add_filter('wp_feed_cache_transient_lifetime', $lifetime);
        try {
            $result = fetch_feed($feed['url']);
        } finally {
            remove_filter('wp_feed_cache_transient_lifetime', $lifetime);
        }
        if ($result instanceof \WP_Error) {
            $code = (string) $result->get_error_code();
            error_log('[feed-item-brief] feed ' . $feed['url'] . ' failed: ' . $code);

            return $code !== '' ? $code : 'error';
        }
        if (!is_object($result) || !method_exists($result, 'get_items')) {
            return 'not_a_feed';
        }
        $limit = max(1, (int) apply_filters('feed_item_brief_items_per_feed', self::ITEMS_PER_FEED));
        $items = [];
        foreach ((array) $result->get_items(0, $limit) as $entry) {
            if (is_object($entry)) {
                $items[] = self::mapItem($entry, $result, $feed);
            }
        }
        if ($items !== []) {
            do_action('feed_item_brief_submit_batch', $items, self::ID, $feed['key']);
        }

        return null;
    }

    /**
     * Map one SimplePie item (case 63). Duck-typed so it works with the
     * SimplePie class names of every supported WordPress version.
     *
     * @param array{key: string, url: string, source_name: string, author: int, timezone: string, enabled: bool, failures: int, last_error: string} $feed
     * @return array<string, mixed>
     */
    public static function mapItem(object $entry, object $source, array $feed): array
    {
        $call = static function (object $object, string $method, mixed ...$args): mixed {
            return method_exists($object, $method) ? $object->{$method}(...$args) : null;
        };
        $content = $call($entry, 'get_content', true);
        if (!is_string($content) || trim($content) === '') {
            $content = $call($entry, 'get_description', true);
        }
        $bylines = [];
        $authors = $call($entry, 'get_authors');
        foreach (is_array($authors) ? $authors : [] as $author) {
            if (!is_object($author)) {
                continue;
            }
            $name = $call($author, 'get_name');
            $email = $call($author, 'get_email');
            if (is_string($name) && trim($name) !== '') {
                $bylines[] = $name;
            } elseif (is_string($email) && $email !== '') {
                $bylines[] = $email;
            }
        }
        $date = $call($entry, 'get_date', '');
        $feedTitle = $call($source, 'get_title');
        $language = $call($source, 'get_language');

        return [
            'url' => (string) ($call($entry, 'get_permalink') ?? ''),
            'guid' => (string) ($call($entry, 'get_id') ?? ''),
            'title' => (string) ($call($entry, 'get_title') ?? ''),
            'body' => is_string($content) ? $content : '',
            'byline' => $bylines,
            'published' => is_string($date) && $date !== '' ? $date : null,
            'feed_url' => $feed['url'],
            'source_key' => $feed['key'],
            'source_name' => $feed['source_name'],
            'feed_title' => is_string($feedTitle) ? $feedTitle : '',
            'language' => is_string($language) ? $language : '',
        ];
    }
}
