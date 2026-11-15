<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Adapter;
use IndianOpinion\FeedItemBrief\DateParser;
use IndianOpinion\FeedItemBrief\DateResolver;
use IndianOpinion\FeedItemBrief\Item;
use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\PageMeta;
use IndianOpinion\FeedItemBrief\ResolvedDate;
use IndianOpinion\FeedItemBrief\ReviewPanel;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class DateTest extends TestCase
{
    private function resolve(array $item, ?PageMeta $page = null, array $batch = [], ?int $earlier = null, ?string $tz = null): ResolvedDate
    {
        $item = Item::fromArray($item + ['url' => 'https://news.example.org/story', 'title' => 'A title']);

        return (new DateResolver())->resolve($item, $page, $batch, $earlier, $this->clock->now(), $tz);
    }

    /** Test 16 (case 18, 20). */
    public function testDateWithoutZoneIsReadInTheFeedZone(): void
    {
        $date = $this->resolve(['published' => '2026-11-14 10:00:00'], null, [], null, 'Asia/Kolkata');

        self::assertTrue($date->has(ResolvedDate::ASSUMED_TZ));
        self::assertSame(ResolvedDate::FEED, $date->reason());
        self::assertSame((new \DateTimeImmutable('2026-11-14T04:30:00Z'))->getTimestamp(), $date->time());
    }

    public function testDateWithoutZoneAndNoFeedZoneIsUtc(): void
    {
        $date = $this->resolve(['published' => '2026-11-14 10:00:00']);
        self::assertSame((new \DateTimeImmutable('2026-11-14T10:00:00Z'))->getTimestamp(), $date->time());
        self::assertTrue($date->has(ResolvedDate::ASSUMED_TZ));
    }

    public function testParsesTimestampRfc2822AndIso8601(): void
    {
        $expected = (new \DateTimeImmutable('2026-11-14T09:00:00Z'))->getTimestamp();
        self::assertSame($expected, DateParser::parse($expected)['time']);
        self::assertSame($expected, DateParser::parse((string) $expected)['time']);
        self::assertSame($expected, DateParser::parse('Sat, 14 Nov 2026 09:00:00 +0000')['time']);
        self::assertSame($expected, DateParser::parse('2026-11-14T14:30:00+05:30')['time']);
        self::assertFalse(DateParser::parse('2026-11-14T14:30:00+05:30')['assumed']);
    }

    /** Test 17 (case 21). */
    public function testEpochZeroOldAndGarbageAreMissing(): void
    {
        foreach ([0, '0', '1970-01-01T00:00:00Z', '1990-06-01T00:00:00Z', 'garbage', 'yesterday', '', null] as $value) {
            self::assertNull(DateParser::parse($value)['time'], var_export($value, true));
            $date = $this->resolve(['published' => $value]);
            self::assertTrue($date->has(ResolvedDate::MISSING), var_export($value, true));
        }
    }

    /** Test 18 (case 22). */
    public function testFutureDateIsNotUsed(): void
    {
        $date = $this->resolve(['published' => '2026-11-14T14:00:00Z']);

        self::assertTrue($date->has(ResolvedDate::FUTURE));
        self::assertSame(ResolvedDate::FIRST_SEEN, $date->reason());
        self::assertSame($this->clock->now()->getTimestamp(), $date->time());

        $soon = $this->resolve(['published' => '2026-11-14T12:05:00Z']);
        self::assertSame(ResolvedDate::FEED, $soon->reason(), 'within the 10 minute tolerance');
    }

    /** Test 19 (case 23). */
    public function testUniformBatchTimeIsTheFeedBuildTime(): void
    {
        $time = (new \DateTimeImmutable('2026-11-14T11:00:00Z'))->getTimestamp();
        $five = $this->resolve(['published' => $time], null, array_fill(0, 5, $time));
        self::assertTrue($five->has(ResolvedDate::UNIFORM));
        self::assertNotSame($time, $five->time());

        $two = $this->resolve(['published' => $time], null, [$time, $time]);
        self::assertFalse($two->has(ResolvedDate::UNIFORM));
        self::assertSame($time, $two->time());
    }

    public function testBatchSubmitDetectsUniformTimes(): void
    {
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $items[] = $this->item(['url' => 'https://news.example.org/s' . $i, 'guid' => 'g' . $i, 'published' => 'Sat, 14 Nov 2026 11:00:00 +0000', 'body' => self::body(160, 'Story ' . $i)]);
        }
        $this->plugin->intake->submitBatch($items, 'core-feed', 'feed-a');

        self::assertStringContainsString(ResolvedDate::UNIFORM, $this->record(1)->dateReason);
    }

    /** Test 20 (case 24). */
    public function testRedatedItemKeepsTheEarliestClaim(): void
    {
        $this->plugin->intake->submit($this->item(['published' => '2026-11-13T09:00:00Z', 'source_key' => 'a']), 'core-feed');
        $this->repo->update(1, ['status' => Status::STALE, 'final_at' => 1]);
        $this->plugin->intake->submit($this->item(['published' => '2026-11-14T09:00:00Z', 'source_key' => 'a', 'body' => self::body(170)]), 'core-feed');

        $record = $this->record(2);
        self::assertStringContainsString(ResolvedDate::BUMPED, $record->dateReason);
        self::assertSame((new \DateTimeImmutable('2026-11-13T09:00:00Z'))->getTimestamp(), $record->resolvedAt);
    }

    /** Test 21 (case 25). */
    public function testPageDatePublishedWinsWhenFarFromTheFeedDate(): void
    {
        $feed = '2026-11-14T09:00:00Z';
        $old = PageMeta::fromHtml('<html><head><script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2026-11-11T09:00:00Z"}</script></head></html>');
        $close = PageMeta::fromHtml('<html><head><script type="application/ld+json">{"@graph":[{"@type":"NewsArticle","datePublished":"2026-11-14T07:00:00Z"}]}</script></head></html>');

        $a = $this->resolve(['published' => $feed], $old);
        self::assertSame(ResolvedDate::PAGE, $a->reason());
        self::assertSame((new \DateTimeImmutable('2026-11-11T09:00:00Z'))->getTimestamp(), $a->time());

        $b = $this->resolve(['published' => $feed], $close);
        self::assertSame(ResolvedDate::FEED, $b->reason());
        self::assertSame((new \DateTimeImmutable($feed))->getTimestamp(), $b->time());
    }

    public function testPageDateSourcesInOrder(): void
    {
        $meta = PageMeta::fromHtml('<html><head><meta property="article:published_time" content="2026-11-10T08:00:00Z"></head><body></body></html>');
        self::assertSame('2026-11-10T08:00:00Z', $meta->publishedTime);
        $time = PageMeta::fromHtml('<html><head></head><body><article><h1>x</h1><time datetime="2026-11-09T08:00:00Z">9 Nov</time></article></body></html>');
        self::assertSame('2026-11-09T08:00:00Z', $time->publishedTime);
        $future = PageMeta::fromHtml('<html><head><meta property="article:published_time" content="2027-01-01T00:00:00Z"></head></html>');
        self::assertSame(ResolvedDate::FEED, $this->resolve(['published' => '2026-11-14T09:00:00Z'], $future)->reason());
    }

    /** Test 22 (case 26). */
    public function testUrlDateSetsTheDay(): void
    {
        $date = $this->resolve(['url' => 'https://news.example.org/2026/11/14/story']);
        self::assertSame(ResolvedDate::URL, $date->reason());
        self::assertSame($this->clock->now()->getTimestamp(), $date->time(), 'first seen is on that day');

        $older = $this->resolve(['url' => 'https://news.example.org/news/2026-11-12/story'], null, [], null, 'Europe/London');
        self::assertSame((new \DateTimeImmutable('2026-11-12T12:00:00Z'))->getTimestamp(), $older->time());

        $compact = $this->resolve(['url' => 'https://news.example.org/20261113/story']);
        self::assertSame((new \DateTimeImmutable('2026-11-13T12:00:00Z'))->getTimestamp(), $compact->time());
    }

    /** Test 23 (case 27). */
    public function testFirstSeenIsUsedWhenNothingElseAndThePanelSaysSo(): void
    {
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/story', 'published' => null]), 'core-feed');
        $this->plugin->processor->run();
        $record = $this->record(1);

        self::assertStringContainsString(ResolvedDate::FIRST_SEEN, $record->dateReason);
        self::assertSame($this->clock->now()->getTimestamp(), $record->resolvedAt);
        $panel = ReviewPanel::data((int) $record->postId);
        self::assertTrue($panel['no_date']);
        self::assertTrue($panel['date_review']);
    }

    /** Test 24 (case 28). */
    public function testStaleAtSubmissionAndAtProcessing(): void
    {
        $old = $this->plugin->intake->submit($this->item(['published' => '2026-11-10T09:00:00Z']), 'core-feed');
        self::assertSame(Status::STALE, $old->status());

        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/fresh', 'guid' => 'fresh', 'published' => '2026-11-14T09:00:00Z']), 'core-feed');
        $this->clock->advance(4 * 86400);
        $this->plugin->processor->run();

        self::assertSame(Status::STALE, $this->record(2)->status);
        self::assertSame([], $this->model->calls);
    }

    public function testMaxAgeZeroMeansNoLimit(): void
    {
        $this->settings(['max_age_hours' => 0]);
        $submission = $this->plugin->intake->submit($this->item(['published' => '2026-01-01T09:00:00Z']), 'core-feed');
        self::assertSame(Status::QUEUED, $submission->status());
    }

    public function testAdapterTimezoneIsUsedForBatchDates(): void
    {
        $adapter = new class () implements Adapter {
            public function id(): string
            {
                return 'zoned';
            }

            public function label(): string
            {
                return 'Zoned';
            }

            public function boot(): void
            {
            }

            public function defaultAuthor(string $sourceKey): ?int
            {
                return 42;
            }

            public function timezone(string $sourceKey): ?string
            {
                return 'Asia/Kolkata';
            }
        };
        $this->plugin->registry->add($adapter);
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(['published' => '2026-11-14 15:00:00', 'source_key' => 'k']), 'zoned');
        $this->plugin->processor->run();
        $record = $this->record(1);

        self::assertSame((new \DateTimeImmutable('2026-11-14T09:30:00Z'))->getTimestamp(), $record->resolvedAt);
        self::assertSame(42, \WpStub::$posts[(int) $record->postId]->post_author);
        self::assertSame((string) $record->id, (string) get_post_meta((int) $record->postId, Meta::RECORD, true));
    }
}
