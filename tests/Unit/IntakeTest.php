<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Exception\InvalidItemException;
use IndianOpinion\FeedItemBrief\Item;
use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\FakeModel;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;
use IndianOpinion\FeedItemBrief\Text;
use IndianOpinion\FeedItemBrief\Url;

final class IntakeTest extends TestCase
{
    /** Test 1 (case 1). */
    public function testActionStoresQueuedRowWithoutHttpOrPost(): void
    {
        $this->plugin->init();
        do_action('feed_item_brief_submit', $this->item(), 'core-feed');

        self::assertCount(1, $this->repo->rows);
        self::assertSame(Status::QUEUED, $this->record(1)->status);
        self::assertSame([], \WpStub::$httpCalls);
        self::assertSame([], \WpStub::$posts);
        self::assertSame([], $this->model->calls);
    }

    public function testFunctionFormReturnsSubmission(): void
    {
        $this->plugin->init();
        $submission = feed_item_brief_submit($this->item(), 'core-feed');

        self::assertTrue($submission->accepted());
        self::assertSame(Status::QUEUED, $submission->status());
        self::assertSame(1, $submission->recordId());
        self::assertNull($submission->duplicateOf());
    }

    /** Test 5 (case 6). */
    public function testUnknownAdapterIsRecordedWithOneNotice(): void
    {
        $this->plugin->intake->submit($this->item(), 'mystery');
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/other', 'guid' => 'x2']), 'mystery');

        self::assertSame('unknown:mystery', $this->record(1)->adapter);
        self::assertSame('unknown:mystery', $this->record(2)->adapter);
        self::assertCount(1, \WpStub::$doingItWrong);
    }

    /** Test 6 (case 7). */
    public function testBadUrlsAreRejected(): void
    {
        foreach (['javascript:alert(1)', '/relative/path', '', 'ftp://files.example.org/a', 'data:text/html,hi'] as $i => $url) {
            $submission = $this->plugin->intake->submit($this->item(['url' => $url, 'guid' => 'g' . $i]), 'manual');
            self::assertFalse($submission->accepted(), $url);
            self::assertSame('bad_url', $submission->reason(), $url);
            self::assertSame(Status::REJECTED, $this->record((int) $submission->recordId())->status);
        }
    }

    public function testUrlIsTrimmedAndEntitiesDecodedOnce(): void
    {
        $item = Item::fromArray(['url' => '  https://news.example.org/a?x=1&amp;y=2 ', 'title' => 'A title']);
        self::assertSame('https://news.example.org/a?x=1&y=2', $item->url());
    }

    public function testGuidIsUsedWhenUrlIsInvalid(): void
    {
        $item = Item::fromArray(['url' => 'not a url', 'guid' => 'https://news.example.org/story', 'title' => 'A title']);
        self::assertSame('https://news.example.org/story', $item->url());
    }

    /** Test 7 (case 8). */
    public function testEmptyOrPunctuationTitleIsRejected(): void
    {
        foreach (['', '  ', '...', '<b> - </b>'] as $i => $title) {
            $submission = $this->plugin->intake->submit($this->item(['title' => $title, 'guid' => 't' . $i, 'url' => 'https://news.example.org/t' . $i]), 'manual');
            self::assertSame('no_title', $submission->reason(), var_export($title, true));
        }
    }

    public function testOgTitleFillsAMissingTitleWhenPageFetchingIsOn(): void
    {
        $this->settings(['fetch_pages' => true]);
        $this->model->queue(self::goodAnswer());
        \WpStub::$http = self::http(200, '<html><head><meta property="og:title" content="Bridge plan approved by council"></head><body></body></html>');

        $submission = $this->plugin->intake->submit($this->item(['title' => '']), 'core-feed');
        self::assertSame(Status::QUEUED, $submission->status());
        $this->plugin->processor->run();

        $record = $this->record(1);
        self::assertSame(Status::DRAFTED, $record->status);
        self::assertSame('Bridge plan approved by council', $record->title);
    }

    /** Test 8 (case 9). */
    public function testBodyReducesToParagraphs(): void
    {
        $html = '<div class="article"><script>var x = 1;</script><style>p{}</style><p>First &amp; best paragraph.</p>'
            . '<iframe src="https://video.example.org"></iframe><div class="share-buttons"><a>Share on X</a></div>'
            . '<figure><img src="a.jpg"><figcaption>Photo: someone</figcaption></figure>'
            . '<form><input name="email"></form><h2>Heading</h2><ul><li>One</li><li>Two</li></ul><p>Last   line<br>broken.</p></div>';

        self::assertSame("First & best paragraph.\n\nHeading\n\nOne\n\nTwo\n\nLast line\n\nbroken.", Text::htmlToText($html));
    }

    /** Test 9 (case 10). */
    public function testLiteralEscapesDecodeInTitleBodyAndByline(): void
    {
        $item = Item::fromArray([
            'url' => 'https://news.example.org/a',
            'title' => 'It’s the mayor speaking',
            'body' => 'Line one\nLine two ’quoted’',
            'byline' => 'By Renée Roe',
        ]);

        self::assertSame("It\u{2019}s the mayor speaking", $item->title());
        self::assertSame("Line one Line two \u{2019}quoted\u{2019}", $item->bodyText());
        self::assertSame(["Ren\u{00E9}e Roe"], $item->bylines());
    }

    /** Test 10 (case 11, 12). */
    public function testSourceNameFallsBackFromOverrideToFeedTitleToResolvedHost(): void
    {
        $base = ['url' => 'https://www.publisher.example/story', 'title' => 'A title', 'guid' => 'https://feeds.service.example/item/1'];

        self::assertSame('Override', Item::fromArray($base + ['source_name' => 'Override', 'feed_title' => 'Feed Title'])->sourceName());
        self::assertSame('Feed Title', Item::fromArray($base + ['feed_title' => 'Feed Title'])->sourceName());
        self::assertSame('publisher.example', Item::fromArray($base)->sourceName());

        add_filter('feed_item_brief_resolve_url', static fn (string $url): string => 'https://m.real-publisher.example/story');
        $this->plugin->intake->submit($base, 'manual');
        self::assertSame('real-publisher.example', $this->record(1)->sourceName);
        self::assertSame('https://m.real-publisher.example/story', $this->record(1)->url);
    }

    public function testResolveUrlFilterResultMustBeValid(): void
    {
        add_filter('feed_item_brief_resolve_url', static fn (): string => 'javascript:void(0)');
        $this->plugin->intake->submit($this->item(), 'manual');
        self::assertSame('https://news.example.org/2026/11/14/river-bridge', $this->record(1)->url);
    }

    /** Test 11 (case 13). */
    public function testMobileAmpAndTrackingVariantsShareOneKey(): void
    {
        $a = Url::articleKey('https://m.example.org/a/b/amp/?utm_source=x&id=2#top');
        $b = Url::articleKey('http://www.example.org/a/b?id=2');

        self::assertSame($b, $a);
        self::assertSame('example.org/a/b?id=2', $a);
        self::assertSame(
            Url::articleKey('https://example.org/x?b=2&a=1'),
            Url::articleKey('https://amp.example.org/x/?a=1&fbclid=z&b=2&outputType=amp&amp=1')
        );
    }

    /** Test 12 (case 14). */
    public function testSameArticleFromTwoFeedsYieldsOneDraftAndOneDuplicate(): void
    {
        $this->model->queue(self::goodAnswer());
        $first = $this->plugin->intake->submit($this->item(['source_key' => 'feed-a']), 'core-feed');
        $second = $this->plugin->intake->submit($this->item([
            'url' => 'https://m.news.example.org/2026/11/14/river-bridge/?utm_campaign=y',
            'guid' => 'other-guid',
            'source_key' => 'feed-b',
        ]), 'core-feed');
        $this->plugin->processor->run();

        self::assertSame(Status::DUPLICATE, $second->status());
        self::assertSame($first->recordId(), $second->duplicateOf());
        self::assertCount(1, \WpStub::$posts);
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
        self::assertSame(Status::DUPLICATE, $this->record(2)->status);

        $again = $this->plugin->intake->submit($this->item(['source_key' => 'feed-a']), 'core-feed');
        self::assertFalse($again->accepted(), 'an identical resubmission from the same feed adds no row');
        self::assertCount(2, $this->repo->rows);
    }

    /** Test 13 (case 15). */
    public function testChangedBodyNotesTheDraftButNotAPublishedPost(): void
    {
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        $postId = (int) $this->record(1)->postId;

        $update = $this->plugin->intake->submit($this->item(['body' => self::body(160, 'A correction was added later.')]), 'core-feed');
        self::assertSame(Status::UPDATE, $update->status());
        self::assertCount(1, \WpStub::$posts);
        $notes = Meta::jsonList($postId, Meta::UPDATES);
        self::assertCount(1, $notes);
        self::assertSame('The source changed after this draft was written', $notes[0]['note']);

        \WpStub::$posts[$postId]->post_status = 'publish';
        $content = \WpStub::$posts[$postId]->post_content;
        $this->plugin->intake->submit($this->item(['body' => self::body(160, 'Another change.')]), 'core-feed');
        self::assertCount(1, Meta::jsonList($postId, Meta::UPDATES));
        self::assertSame($content, \WpStub::$posts[$postId]->post_content);
        self::assertSame(Status::UPDATE, $this->record(3)->status);
    }

    public function testWhitespaceChangeIsNotAnUpdate(): void
    {
        $this->plugin->intake->submit($this->item(['source_key' => 'a']), 'core-feed');
        $second = $this->plugin->intake->submit($this->item(['source_key' => 'b', 'body' => str_replace(' ', "  \n ", self::body())]), 'core-feed');
        self::assertSame(Status::DUPLICATE, $second->status());
    }

    /** Test 14 (case 16). */
    public function testOwnHostIsRejected(): void
    {
        foreach (['https://mysite.example/2026/11/14/post', 'https://www.mysite.example/a', 'https://amp.mysite.example/b'] as $i => $url) {
            $submission = $this->plugin->intake->submit($this->item(['url' => $url, 'guid' => 's' . $i]), 'manual');
            self::assertSame('self', $submission->reason(), $url);
        }
    }

    /** Test 15 (case 17). */
    public function testBylineCleanup(): void
    {
        $item = Item::fromArray([
            'url' => 'https://news.example.org/a',
            'title' => 'A title',
            'source_name' => 'Example News',
            'byline' => ['By Jane Roe', 'jroe@example.org (Jane Roe)', 'editor@example.org', 'Example News', str_repeat('x', 150), 'jane roe'],
        ]);
        self::assertSame(['Jane Roe'], $item->bylines());

        $three = Item::fromArray(['url' => 'https://news.example.org/a', 'title' => 'A title', 'byline' => ['A One', 'B Two', 'C Three']]);
        self::assertSame('A One, B Two and C Three', \IndianOpinion\FeedItemBrief\Byline::join($three->bylines()));
        self::assertSame('A One and B Two', \IndianOpinion\FeedItemBrief\Byline::join(['A One', 'B Two']));

        $none = Item::fromArray(['url' => 'https://news.example.org/a', 'title' => 'A title', 'byline' => 'staff@example.org']);
        self::assertSame([], $none->bylines());
    }

    /** Test 61 (failure table). */
    public function testDatabaseErrorDoesNotThrowIntoTheImporter(): void
    {
        $this->repo->failInsert = true;
        $submission = $this->plugin->intake->submit($this->item(), 'core-feed');

        self::assertFalse($submission->accepted());
        self::assertSame('failed', $submission->status());
    }

    public function testOversizedFieldsAreCutAndNoted(): void
    {
        $item = Item::fromArray([
            'url' => 'https://news.example.org/a',
            'title' => str_repeat('word ', 600),
            'body' => str_repeat('a', Item::MAX_BODY_BYTES + 10),
        ]);
        self::assertContains('title', $item->truncated());
        self::assertContains('body', $item->truncated());
        self::assertLessThanOrEqual(Item::MAX_BODY_BYTES, strlen($item->bodyHtml()));
    }

    public function testInvalidItemExceptionCarriesReason(): void
    {
        try {
            Item::fromArray(['url' => 'nope', 'title' => 'x']);
            self::fail('expected an exception');
        } catch (InvalidItemException $e) {
            self::assertSame('bad_url', $e->reason());
        }
    }

    /** Test 63 (case 19). */
    public function testLanguageFallsBackFromItemToFeedToPageToUnd(): void
    {
        self::assertSame('en-GB', Item::fromArray(['url' => 'https://a.example/x', 'title' => 'A title', 'language' => 'en_gb'])->language());
        self::assertNull(Item::fromArray(['url' => 'https://a.example/x', 'title' => 'A title', 'language' => 'not a tag!'])->language());

        $this->settings(['fetch_pages' => true]);
        \WpStub::$http = self::http(200, '<html lang="de"><head><title>Seite</title></head></html>');
        $this->model->queue(FakeModel::answer(['Der Rat hat am Dienstag den Plan genehmigt.'], 'Der Rat genehmigt den Plan fuer die Bruecke', 'de'));
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame('de', $this->record(1)->language);

        \WpStub::$http = null;
        $this->settings(['fetch_pages' => false]);
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/2026/11/14/other', 'guid' => 'o']), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame('und', $this->record(2)->language);
    }
}
