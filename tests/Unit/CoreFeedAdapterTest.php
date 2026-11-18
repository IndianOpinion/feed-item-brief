<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedItemBrief\Admin\Pages;
use IndianOpinion\FeedItemBrief\ResolvedDate;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class CoreFeedAdapterTest extends TestCase
{
    private const RSS = 'https://news.example.org/feed/';
    private const ATOM = 'https://nouvelles.example.fr/atom.xml';

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::$feeds = [
            self::RSS => dirname(__DIR__) . '/fixtures/rss2.xml',
            self::ATOM => dirname(__DIR__) . '/fixtures/atom.xml',
        ];
        $this->plugin->init();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapped(string $url, string $override = '', int $index = 0): array
    {
        $feed = CoreFeedAdapter::sanitiseFeed(['url' => $url, 'source_name' => $override]);
        self::assertNotNull($feed);
        $simplepie = fetch_feed($url);
        $items = $simplepie->get_items(0, 10);

        return CoreFeedAdapter::mapItem($items[$index], $simplepie, $feed);
    }

    /** Test 2 (case 2, 61 to 63). */
    public function testRssItemMapping(): void
    {
        $item = $this->mapped(self::RSS);

        self::assertSame('https://news.example.org/2026/11/14/river-bridge?utm_source=rss', $item['url']);
        self::assertSame('example-123', $item['guid']);
        self::assertSame('Council approves river bridge plan', $item['title']);
        self::assertStringContainsString('approved the plan for the river bridge', $item['body']);
        self::assertSame(['jroe@example.org (Jane Roe)'], $item['byline'], 'raw; the item normalises it');
        self::assertSame('Sat, 14 Nov 2026 09:00:00 +0000', $item['published']);
        self::assertSame('en-GB', $item['language']);
        self::assertSame('Example News', $item['feed_title']);
        self::assertSame(CoreFeedAdapter::keyFor(self::RSS), $item['source_key']);

        $second = $this->mapped(self::RSS, 'Override Name', 1);
        self::assertSame('Only a description here.', $second['body'], 'description when there is no full content');
        self::assertSame('Override Name', $second['source_name']);
    }

    public function testAtomItemMapping(): void
    {
        $item = $this->mapped(self::ATOM);

        self::assertSame('https://nouvelles.example.fr/pont', $item['url']);
        self::assertSame('urn:uuid:entry-1', $item['guid']);
        self::assertSame(['Marie Curie'], $item['byline']);
        self::assertSame('2026-11-14T08:15:00+01:00', $item['published']);
        self::assertSame('fr', $item['language']);
        self::assertSame('Les Nouvelles', $item['feed_title']);
    }

    public function testPollSubmitsItemsAsOneBatch(): void
    {
        CoreFeedAdapter::saveFeeds([['url' => self::RSS, 'timezone' => 'Europe/London', 'author' => 9]]);
        $this->plugin->coreFeed->poll();

        self::assertCount(2, $this->repo->rows);
        $first = $this->record(1);
        self::assertSame('core-feed', $first->adapter);
        self::assertSame(Status::QUEUED, $first->status);
        self::assertSame('Example News', $first->sourceName);
        self::assertSame(['Jane Roe'], $first->bylines);
        self::assertSame('en-GB', $first->language);
        self::assertSame(9, $first->meta['author']);
        self::assertSame((new \DateTimeImmutable('2026-11-14T09:00:00Z'))->getTimestamp(), $first->resolvedAt);

        $this->plugin->coreFeed->poll();
        self::assertCount(2, $this->repo->rows, 'a second poll of the same items adds nothing');
    }

    /** Test 56 (case 64). */
    public function testFailingFeedIsLoggedAndTheNextFetched(): void
    {
        CoreFeedAdapter::saveFeeds([['url' => 'https://broken.example/feed'], ['url' => self::RSS]]);
        $this->plugin->coreFeed->poll();

        self::assertCount(2, $this->repo->rows, 'the next feed is fetched');
        $feeds = CoreFeedAdapter::feeds();
        self::assertSame(1, $feeds[0]['failures']);
        self::assertSame('simplepie-error', $feeds[0]['last_error']);
        self::assertFalse(CoreFeedAdapter::isFailing($feeds[0]));

        $this->plugin->coreFeed->poll();
        $this->plugin->coreFeed->poll();
        $feeds = CoreFeedAdapter::feeds();
        self::assertTrue(CoreFeedAdapter::isFailing($feeds[0]));
        self::assertSame(0, $feeds[1]['failures']);

        \WpStub::$feeds['https://broken.example/feed'] = dirname(__DIR__) . '/fixtures/atom.xml';
        $this->plugin->coreFeed->poll();
        self::assertFalse(CoreFeedAdapter::isFailing(CoreFeedAdapter::feeds()[0]), 'a success clears it');
    }

    /** Test 57 (case 65, 66). */
    public function testBatchSubmitAndCacheFilterRemovedAfterTheFetch(): void
    {
        $seen = [];
        add_action('feed_item_brief_submit_batch', static function (array $items, string $adapter, string $key) use (&$seen): void {
            $seen[] = [count($items), $adapter, $key, has_filter('wp_feed_cache_transient_lifetime')];
        }, 5, 3);
        $feed = CoreFeedAdapter::sanitiseFeed(['url' => self::RSS]);
        self::assertNotNull($feed);
        self::assertFalse(has_filter('wp_feed_cache_transient_lifetime'));
        $this->plugin->coreFeed->fetchOne($feed);

        self::assertSame([[2, 'core-feed', CoreFeedAdapter::keyFor(self::RSS), false]], $seen);
        self::assertFalse(has_filter('wp_feed_cache_transient_lifetime'));
    }

    public function testUniformFeedDatesSeenThroughTheBatch(): void
    {
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->item(['url' => 'https://news.example.org/u' . $i, 'guid' => 'u' . $i, 'published' => 'Sat, 14 Nov 2026 11:00:00 +0000', 'body' => self::body(160, 'Item ' . $i)]);
        }
        do_action('feed_item_brief_submit_batch', $items, 'core-feed', 'k');
        self::assertStringContainsString(ResolvedDate::UNIFORM, $this->record(3)->dateReason);
    }

    public function testRotationTakesTenFeedsPerRun(): void
    {
        $feeds = [];
        for ($i = 0; $i < 12; $i++) {
            $feeds[] = ['url' => 'https://f' . $i . '.example/feed'];
        }
        CoreFeedAdapter::saveFeeds($feeds);
        $this->plugin->coreFeed->poll();
        $failures = array_column(CoreFeedAdapter::feeds(), 'failures');
        self::assertSame([1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0], $failures);
        $this->plugin->coreFeed->poll();
        self::assertSame([2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1], array_column(CoreFeedAdapter::feeds(), 'failures'));
    }

    public function testFeedListParsingAndLimits(): void
    {
        $parsed = Pages::parseFeeds("https://a.example/feed | A News | 3 | Asia/Kolkata | 1\nftp://bad.example/feed\nhttps://b.example/feed|||Not/AZone|0\n");
        CoreFeedAdapter::saveFeeds($parsed);
        $feeds = CoreFeedAdapter::feeds();

        self::assertCount(2, $feeds);
        self::assertSame(['A News', 3, 'Asia/Kolkata', true], [$feeds[0]['source_name'], $feeds[0]['author'], $feeds[0]['timezone'], $feeds[0]['enabled']]);
        self::assertSame(['', false], [$feeds[1]['timezone'], $feeds[1]['enabled']]);
        self::assertSame(3, $this->plugin->coreFeed->defaultAuthor($feeds[0]['key']));
        self::assertSame('Asia/Kolkata', $this->plugin->coreFeed->timezone($feeds[0]['key']));

        $many = [];
        for ($i = 0; $i < 250; $i++) {
            $many[] = ['url' => 'https://m' . $i . '.example/feed'];
        }
        CoreFeedAdapter::saveFeeds($many);
        self::assertCount(200, CoreFeedAdapter::feeds());
    }
}
