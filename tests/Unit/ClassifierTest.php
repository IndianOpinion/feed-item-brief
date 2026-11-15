<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\BodyClass;
use IndianOpinion\FeedItemBrief\Classifier;
use IndianOpinion\FeedItemBrief\Item;
use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\PageMeta;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class ClassifierTest extends TestCase
{
    private function classify(array $item, ?PageMeta $page = null): BodyClass
    {
        $item = Item::fromArray($item + ['url' => 'https://news.example.org/story', 'title' => 'A plain title']);

        return (new Classifier(new Settings()))->classify($item, $page);
    }

    /** Test 27 (case 31). */
    public function testVideoUrlAndVideoObjectPageAreNotArticles(): void
    {
        self::assertSame(BodyClass::NOT_ARTICLE, $this->classify(['url' => 'https://news.example.org/video/clip-1', 'body' => self::body()])->kind);
        $page = PageMeta::fromHtml('<html><head><script type="application/ld+json">{"@type":"VideoObject","name":"Clip"}</script></head></html>');
        self::assertSame(BodyClass::NOT_ARTICLE, $this->classify(['body' => self::body()], $page)->kind);

        $article = PageMeta::fromHtml('<html><head><script type="application/ld+json">[{"@type":"NewsArticle"},{"@type":"VideoObject"}]</script></head></html>');
        self::assertSame(BodyClass::FULL, $this->classify(['body' => self::body()], $article)->kind, 'an article with an embedded video is still an article');

        foreach (['/gallery/x', '/tag/politics/', '/author/jane/', '/search?q=x', '/live/'] as $path) {
            self::assertSame(BodyClass::NOT_ARTICLE, $this->classify(['url' => 'https://news.example.org' . $path, 'body' => self::body()])->kind, $path);
        }
    }

    /** Test 28 (case 32). */
    public function testSponsoredInTitlePathOrBody(): void
    {
        self::assertSame(BodyClass::SPONSORED, $this->classify(['title' => 'Sponsored: the best kettles', 'body' => self::body()])->kind);
        self::assertSame(BodyClass::SPONSORED, $this->classify(['url' => 'https://news.example.org/partner-content/bank-story', 'body' => self::body()])->kind);
        self::assertSame(BodyClass::SPONSORED, $this->classify(['body' => '<p>PRESS RELEASE: Acme announces a widget.</p>' . self::body()])->kind);
        self::assertSame(BodyClass::FULL, $this->classify(['body' => self::body()])->kind);

        add_filter('feed_item_brief_sponsored_phrases', static fn (array $p): array => [...$p, 'brought to you by']);
        self::assertSame(BodyClass::SPONSORED, $this->classify(['body' => '<p>Brought to you by Acme.</p>' . self::body()])->kind);
    }

    /** Test 29 (case 33). */
    public function testTitleOnlyItemInEachNoBodyMode(): void
    {
        self::assertSame(BodyClass::NO_BODY, $this->classify(['body' => ''])->kind);

        $this->settings(['no_body_mode' => 'link_only']);
        $class = $this->classify(['body' => 'Too short.']);
        self::assertSame(BodyClass::LINK_ONLY, $class->kind);
        self::assertSame(BodyClass::NO_BODY, $class->reason);

        $calls = 0;
        add_filter('feed_item_brief_fetch_body', static function ($html, $item) use (&$calls) {
            $calls++;

            return self::body(200);
        }, 10, 2);
        self::assertSame(BodyClass::FULL, $this->classify(['body' => ''])->kind);
        self::assertSame(1, $calls, 'the extractor is asked once');
    }

    public function testJsonLdArticleBodyFillsANoBodyItem(): void
    {
        $text = trim(strip_tags(self::body(60)));
        $page = PageMeta::fromHtml('<html><head><script type="application/ld+json">' . json_encode(['@type' => 'NewsArticle', 'articleBody' => $text]) . '</script></head></html>');
        self::assertSame(BodyClass::SHORT, $this->classify(['body' => ''], $page)->kind);
    }

    /** Test 30 (case 34). */
    public function testPaywalledTeaserInEachMode(): void
    {
        $teaser50 = trim(strip_tags(self::body(50))) . ' Subscribe to read the full story.';
        $teaser20 = trim(strip_tags(self::body(20))) . ' Subscribe to read more.';

        $this->settings(['paywall_mode' => 'teaser']);
        $class = $this->classify(['body' => $teaser50]);
        self::assertSame(BodyClass::TEASER, $class->kind);
        self::assertTrue($class->excerptNote);
        self::assertSame([40, 80], [$class->minWords, $class->maxWords]);
        self::assertStringNotContainsStringIgnoringCase('subscribe', $class->text);

        self::assertSame(BodyClass::LINK_ONLY, $this->classify(['body' => $teaser20])->kind);

        $this->settings(['paywall_mode' => 'skip']);
        self::assertSame(BodyClass::PAYWALLED, $this->classify(['body' => $teaser50])->kind);

        $this->settings(['paywall_mode' => 'link_only']);
        self::assertSame(BodyClass::LINK_ONLY, $this->classify(['body' => $teaser50])->kind);
    }

    public function testPaywallDefaultIsLinkOnlyAndOtherSignals(): void
    {
        self::assertSame('link_only', (new Settings())->paywallMode());
        $page = PageMeta::fromHtml('<html><head><script type="application/ld+json">{"@type":"NewsArticle","isAccessibleForFree":"False"}</script></head></html>');
        self::assertSame('paywall_jsonld', $this->classify(['body' => self::body()], $page)->reason);
        self::assertSame('paywall_cut', $this->classify(['body' => trim(strip_tags(self::body(60))) . ' [...]'])->reason);
        self::assertSame(BodyClass::FULL, $this->classify(['body' => self::body(200) . '<p>Read more...</p>'])->kind, '120 words or more is not a cut teaser');
    }

    /** Test 31 (case 35). */
    public function testShortAndFullTargets(): void
    {
        $short = $this->classify(['body' => self::body(60)]);
        self::assertSame(BodyClass::SHORT, $short->kind);
        self::assertSame([40, 80], [$short->minWords, $short->maxWords]);

        $full = $this->classify(['body' => self::body(400)]);
        self::assertSame(BodyClass::FULL, $full->kind);
        self::assertSame([100, 170], [$full->minWords, $full->maxWords]);

        add_filter('feed_item_brief_length', static fn (array $r, string $mode): array => $mode === 'full' ? ['min' => 80, 'max' => 120] : $r, 10, 2);
        $tuned = $this->classify(['body' => self::body(400)]);
        self::assertSame([80, 120], [$tuned->minWords, $tuned->maxWords]);
    }

    public function testCjkWordCount(): void
    {
        self::assertSame(3, \IndianOpinion\FeedItemBrief\Text::wordCount("\u{6771}\u{4EAC}\u{90FD}\u{5E81}\u{767A}"));
        self::assertSame(4, \IndianOpinion\FeedItemBrief\Text::wordCount("\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947} \u{0926}\u{0941}\u{0928}\u{093F}\u{092F}\u{093E} it's fine"));
    }

    /** Test 32 (case 36). */
    public function testLinkOnlyDraftHasTitleTeaserAttributionAndNoModelCall(): void
    {
        $teaser = trim(strip_tags(self::body(20))) . ' Subscribe to read more.';
        $this->plugin->intake->submit($this->item(['body' => $teaser]), 'core-feed');
        $this->plugin->processor->run();
        $record = $this->record(1);

        self::assertSame(Status::DRAFTED, $record->status);
        self::assertSame('link_only', $record->mode);
        self::assertSame([], $this->model->calls);
        self::assertSame(0, $this->plugin->gate->callsToday(), 'link-only drafts do not use the cap');
        $post = \WpStub::$posts[(int) $record->postId];
        self::assertSame('Council approves river bridge plan', $post->post_title);
        self::assertStringContainsString('The council met on Tuesday', $post->post_content);
        self::assertStringNotContainsString('Subscribe', $post->post_content);
        self::assertStringContainsString("<p></p>", $post->post_content);
        self::assertStringContainsString('feed-item-brief-attribution', $post->post_content);
        self::assertSame('link_only', get_post_meta((int) $record->postId, Meta::MODE, true));
    }

    /** Test 58 (case 67, 68). */
    public function testPageFailuresLetTheItemProceedOnFeedData(): void
    {
        $this->settings(['fetch_pages' => true]);
        $responses = [
            self::http(404, 'Not found'),
            static fn (): \WP_Error => new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
            self::http(200, '%PDF-1.4', ['content-type' => 'application/pdf']),
        ];
        foreach ($responses as $i => $response) {
            \WpStub::$http = $response;
            $this->model->queue(self::goodAnswer());
            $submission = $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/2026/11/14/p' . $i, 'guid' => 'p' . $i]), 'core-feed');
            $this->plugin->processor->run();
            $record = $this->record((int) $submission->recordId());
            self::assertSame(Status::DRAFTED, $record->status, 'response ' . $i);
            self::assertNotSame('ok', $record->meta['page']);
        }
        self::assertSame('http_404', $this->record(1)->meta['page']);
        self::assertSame('fetch_error: http_request_failed', $this->record(2)->meta['page']);
        self::assertSame('not_html', $this->record(3)->meta['page']);
    }

    public function testPageFetchUsesSafeRequestWithLimits(): void
    {
        $this->settings(['fetch_pages' => true]);
        $seen = [];
        \WpStub::$http = static function (string $url, array $args) use (&$seen): array {
            $seen = $args;

            return ['response' => ['code' => 200], 'headers' => ['content-type' => 'text/html'], 'body' => '<html></html>'];
        };
        $this->plugin->fetcher->fetch('https://news.example.org/a');
        self::assertSame(8, $seen['timeout']);
        self::assertSame(3, $seen['redirection']);
        self::assertSame(1048576, $seen['limit_response_size']);
        self::assertStringContainsString('feed-item-brief/', $seen['user-agent']);
    }

    public function testPageFetchingIsOffByDefault(): void
    {
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame([], \WpStub::$httpCalls);
    }

    /** Test 59 (case 69). */
    public function testNosnippetPageBecomesLinkOnly(): void
    {
        $this->settings(['fetch_pages' => true]);
        \WpStub::$http = self::http(200, '<html><head><meta name="robots" content="index, nosnippet"></head></html>');
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();

        self::assertSame('link_only', $this->record(1)->mode);
        self::assertSame([], $this->model->calls);

        \WpStub::$http = self::http(200, '<html><head></head></html>', ['content-type' => 'text/html', 'x-robots-tag' => 'noarchive']);
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/2026/11/14/b', 'guid' => 'b']), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame('link_only', $this->record(2)->mode);
    }

    /** Test 60 (case 70). */
    public function testCrossHostRedirectWithoutCanonicalKeepsTheOriginalUrl(): void
    {
        $this->settings(['fetch_pages' => true]);
        $page = PageMeta::fromHtml('<html><head><title>x</title></head></html>', 'https://elsewhere.example/landing');
        $withCanonical = PageMeta::fromHtml('<html><head><link rel="canonical" href="https://elsewhere.example/real-story"></head></html>', 'https://www.elsewhere.example/landing');

        $fetcher = new class ([$page, $withCanonical]) extends \IndianOpinion\FeedItemBrief\PageFetcher {
            /** @param list<PageMeta> $pages */
            public function __construct(private array $pages)
            {
            }

            public function fetch(string $url): array
            {
                return ['page' => array_shift($this->pages), 'reason' => 'ok'];
            }
        };
        $model = $this->model;
        $plugin = new \IndianOpinion\FeedItemBrief\Plugin(['clock' => $this->clock, 'repository' => $this->repo, 'model' => static fn () => $model, 'fetcher' => $fetcher]);
        $this->model->queue(self::goodAnswer(), self::goodAnswer());

        $plugin->intake->submit($this->item(), 'core-feed');
        $plugin->processor->run();
        self::assertSame('https://news.example.org/2026/11/14/river-bridge', $this->record(1)->url);

        $plugin->intake->submit($this->item(['url' => 'https://news.example.org/2026/11/14/moved', 'guid' => 'moved']), 'core-feed');
        $plugin->processor->run();
        self::assertSame('https://elsewhere.example/real-story', $this->record(2)->url);
        self::assertSame('https://elsewhere.example/real-story', get_post_meta((int) $this->record(2)->postId, Meta::SOURCE_URL, true));
    }
}
