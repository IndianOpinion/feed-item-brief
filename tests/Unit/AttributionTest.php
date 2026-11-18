<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Attribution;
use IndianOpinion\FeedItemBrief\AttributionRenderer;
use IndianOpinion\FeedItemBrief\Frontend;
use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\ReviewPanel;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class AttributionTest extends TestCase
{
    private function draft(array $item = []): int
    {
        $this->model->queue(self::goodAnswer());
        $submission = $this->plugin->intake->submit($this->item($item), 'core-feed');
        $this->plugin->processor->run();

        return (int) $this->record((int) $submission->recordId())->postId;
    }

    /** Test 25 (case 29). */
    public function testBlockShowsSiteZoneTimeAndIsoUtcDatetime(): void
    {
        \WpStub::$options['timezone_string'] = 'Asia/Kolkata';
        $html = $this->plugin->renderer->render(new Attribution('https://news.example.org/a', 'Example News', ['Jane Roe'], (new \DateTimeImmutable('2026-11-14T09:00:00Z'))->getTimestamp()));

        self::assertStringContainsString('<time datetime="2026-11-14T09:00:00Z">November 14, 2026 2:30 pm</time>', $html);
        self::assertStringContainsString('Source: <a href="https://news.example.org/a" rel="nofollow noopener">Example News</a>', $html);
        self::assertStringContainsString('By Jane Roe', $html);
        self::assertStringContainsString('This summary was drafted with the help of software and checked by an editor.', $html);
    }

    public function testExcerptNoteAndDisclosureSwitch(): void
    {
        $this->settings(['disclosure_on' => false]);
        $html = $this->plugin->renderer->render(new Attribution('https://news.example.org/a', 'Example News', [], 1794646800, true));
        self::assertStringContainsString('Summary based on the publicly available excerpt.', $html);
        self::assertStringNotContainsString('drafted with the help of software', $html);
        self::assertStringNotContainsString('By ', $html, 'no byline, no By line');
    }

    public function testTeaserDraftCarriesTheExcerptNote(): void
    {
        $this->settings(['paywall_mode' => 'teaser']);
        $teaser = trim(strip_tags(self::body(50))) . ' Subscribe to read the full story.';
        $postId = $this->draft(['body' => $teaser]);
        self::assertStringContainsString('Summary based on the publicly available excerpt.', \WpStub::$posts[$postId]->post_content);
        self::assertStringContainsString('Only a short public excerpt', $this->model->calls[0]['messages'][0]['content']);
    }

    /** Test 43 (case 45). */
    public function testStaticFallbackReadsWithoutTheRenderCallback(): void
    {
        $postId = $this->draft();
        $content = \WpStub::$posts[$postId]->post_content;
        $withoutBlocks = (string) preg_replace('/<!--.*?-->/s', '', $content);

        self::assertStringContainsString('<a href="https://news.example.org/2026/11/14/river-bridge" rel="nofollow noopener">Example News</a>', $withoutBlocks);
        self::assertStringContainsString('By Jane Roe', $withoutBlocks);
        self::assertStringContainsString('<time datetime="2026-11-14T09:00:00Z">', $withoutBlocks);
        self::assertStringContainsString('<!-- wp:feed-item-brief/attribution {"lock":{"move":false,"remove":true}} -->', $content);
    }

    public function testBlockRenderReadsMetaNotSavedMarkup(): void
    {
        $postId = $this->draft();
        update_post_meta($postId, Meta::SOURCE_NAME, 'Corrected Name');
        $this->plugin->frontend->registerBlock();
        $callback = \WpStub::$blocks[AttributionRenderer::BLOCK]['render_callback'];
        $block = new \stdClass();
        $block->context = ['postId' => $postId];
        $html = $callback([], 'saved fallback', $block);

        self::assertStringContainsString('>Corrected Name</a>', $html);
    }

    /** Test 44 (case 46). */
    public function testDeletedBlockIsRenderedOnTheContent(): void
    {
        $postId = $this->draft();
        \WpStub::$posts[$postId]->post_content = '<p>Only the summary is left.</p>';
        \WpStub::$currentPost = $postId;
        $this->plugin->frontend->hooks();

        $html = apply_filters('the_content', \WpStub::$posts[$postId]->post_content);
        self::assertStringContainsString('Only the summary is left.', $html);
        self::assertSame(1, substr_count($html, 'feed-item-brief-attribution"'));
        self::assertStringContainsString('rel="nofollow noopener">Example News</a>', $html);

        self::assertSame($html, apply_filters('the_content', $html), 'not appended twice');

        $emptied = apply_filters('the_content', '<div class="feed-item-brief-attribution">x</div>');
        self::assertStringContainsString('rel="nofollow noopener">Example News</a>', $emptied, 'a wrapper with its link removed does not count');
        $typed = apply_filters('the_content', '<p>feed-item-brief-attribution</p>');
        self::assertStringContainsString('rel="nofollow noopener">Example News</a>', $typed, 'the class name typed as text does not count');
    }

    public function testPostsWithoutAttributionMetaAreUntouched(): void
    {
        \WpStub::$posts[5] = new \WP_Post(['ID' => 5, 'post_content' => 'Hello']);
        \WpStub::$currentPost = 5;
        self::assertSame('Hello', $this->plugin->frontend->ensureAttribution('Hello'));
    }

    /** Test 45 (case 47). */
    public function testRestylingFilterIsValidated(): void
    {
        $a = new Attribution('https://news.example.org/a?x=1&y=2', 'Example News', [], 1794646800);
        add_filter('feed_item_brief_attribution_html', static fn (string $html): string => '<p>Credit removed</p>');
        $html = $this->plugin->renderer->render($a);
        self::assertStringContainsString('rel="nofollow noopener">Example News</a>', $html);
        self::assertCount(1, \WpStub::$doingItWrong);
        $this->plugin->renderer->render($a);
        self::assertCount(1, \WpStub::$doingItWrong, 'once per request');

        \WpStub::$hooks = [];
        add_filter('feed_item_brief_attribution_html', static fn (string $html, Attribution $a): string => '<aside class="credit">Via <a class="x" href="' . esc_url($a->sourceUrl) . '">Example News (original)</a></aside>', 10, 2);
        $styled = $this->plugin->renderer->render($a);
        self::assertStringContainsString('<aside class="credit">', $styled);
        self::assertStringContainsString(AttributionRenderer::WRAPPER, $styled);
    }

    /** Test 46 (case 48). */
    public function testAdministratorCorrectionUpdatesMetaAndHistoryAndEmptyUrlIsRefused(): void
    {
        $postId = $this->draft();
        $result = $this->plugin->panel->correct($postId, [
            'source_name' => 'Example News Ltd',
            'source_url' => 'https://news.example.org/2026/11/14/river-bridge-corrected',
            'byline' => "Jane Roe\nSam Lee",
            'published' => '2026-11-14 08:00',
        ], 3);

        self::assertTrue($result);
        self::assertSame('Example News Ltd', get_post_meta($postId, Meta::SOURCE_NAME, true));
        self::assertSame(['Jane Roe', 'Sam Lee'], Meta::jsonList($postId, Meta::BYLINE));
        self::assertSame((new \DateTimeImmutable('2026-11-14T08:00:00Z'))->getTimestamp(), (int) get_post_meta($postId, Meta::RESOLVED, true));
        $history = Meta::jsonList($postId, Meta::CORRECTIONS);
        self::assertCount(4, $history);
        self::assertSame(3, $history[0]['user']);
        self::assertStringContainsString('river-bridge-corrected', \WpStub::$posts[$postId]->post_content, 'static fallback refreshed');

        foreach (['', 'javascript:alert(1)', '/relative'] as $bad) {
            $refused = $this->plugin->panel->correct($postId, ['source_url' => $bad], 3);
            self::assertInstanceOf(\WP_Error::class, $refused);
        }
        self::assertSame('https://news.example.org/2026/11/14/river-bridge-corrected', get_post_meta($postId, Meta::SOURCE_URL, true));
    }

    public function testCorrectionFormNeedsNonceAndCapability(): void
    {
        $postId = $this->draft();
        $_POST = ['_feed_item_brief_nonce' => 'nonce-' . ReviewPanel::NONCE, 'feed_item_brief' => ['source_name' => 'Hacked']];
        \WpStub::$caps = ['edit_post' => true];
        $this->plugin->panel->save($postId);
        self::assertSame('Example News', get_post_meta($postId, Meta::SOURCE_NAME, true), 'needs the settings capability');

        \WpStub::$caps = ['edit_post' => true, 'manage_options' => true];
        $_POST['_feed_item_brief_nonce'] = 'wrong';
        $this->plugin->panel->save($postId);
        self::assertSame('Example News', get_post_meta($postId, Meta::SOURCE_NAME, true), 'needs a valid nonce');

        $_POST['_feed_item_brief_nonce'] = 'nonce-' . ReviewPanel::NONCE;
        $this->plugin->panel->save($postId);
        self::assertSame('Hacked', get_post_meta($postId, Meta::SOURCE_NAME, true));
        $_POST = [];
    }

    /** Test 47 (case 49). */
    public function testPublishingWithoutAttributionMetaReturnsToDraft(): void
    {
        $postId = $this->draft();
        $this->plugin->frontend->hooks();
        delete_post_meta($postId, Meta::SOURCE_URL);
        wp_update_post(['ID' => $postId, 'post_status' => 'publish']);

        self::assertSame('draft', \WpStub::$posts[$postId]->post_status);
        self::assertSame($postId, get_transient(Frontend::NOTICE_TRANSIENT . '1'));

        delete_post_meta($postId, Meta::RECORD);
        wp_update_post(['ID' => $postId, 'post_status' => 'future']);
        self::assertSame('draft', \WpStub::$posts[$postId]->post_status, 'found through the record table');

        update_post_meta($postId, Meta::SOURCE_URL, 'https://news.example.org/ok');
        wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
        self::assertSame('publish', \WpStub::$posts[$postId]->post_status);
    }

    public function testOtherPostsPublishNormally(): void
    {
        $this->plugin->frontend->hooks();
        $id = wp_insert_post(['post_title' => 'Mine', 'post_status' => 'draft']);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        self::assertSame('publish', \WpStub::$posts[$id]->post_status);
    }

    /** Test 48 (case 50, 51). */
    public function testMetaIsReadOnlyInRestAndHiddenFromNonEditors(): void
    {
        $postId = $this->draft();
        $this->plugin->panel->registerMeta();
        $args = \WpStub::$registeredMeta[Meta::SOURCE_URL];
        self::assertTrue($args['show_in_rest']);
        $auth = $args['auth_callback'];

        \WpStub::$caps = ['edit_post' => true];
        self::assertTrue($auth(false, Meta::SOURCE_URL, $postId, 1, 'read'));
        self::assertFalse($auth(true, Meta::SOURCE_URL, $postId, 1, 'edit_post_meta'), 'no writes through REST');
        \WpStub::$caps = [];
        self::assertFalse($auth(false, Meta::SOURCE_URL, $postId, 1, 'read'));

        $post = \WpStub::$posts[$postId];
        $response = new \WP_REST_Response(['id' => $postId, 'meta' => [Meta::SOURCE_URL => 'x', 'other' => 'y']]);
        $this->plugin->panel->hideMeta($response, $post);
        self::assertSame(['other' => 'y'], $response->get_data()['meta']);

        \WpStub::$caps = ['edit_post' => true];
        $response = new \WP_REST_Response(['id' => $postId, 'meta' => [Meta::SOURCE_URL => 'x']]);
        $this->plugin->panel->hideMeta($response, $post);
        self::assertSame([Meta::SOURCE_URL => 'x'], $response->get_data()['meta']);
    }

    public function testPanelIsHiddenFromUsersWhoCannotEdit(): void
    {
        $postId = $this->draft();
        \WpStub::$caps = [];
        ob_start();
        $this->plugin->panel->render(\WpStub::$posts[$postId]);
        self::assertSame('', ob_get_clean());

        \WpStub::$caps = ['edit_post' => true];
        ob_start();
        $this->plugin->panel->render(\WpStub::$posts[$postId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Example News', $html);
        self::assertStringContainsString('fake/model', $html);
        self::assertStringNotContainsString('feed_item_brief[source_url]', $html, 'correction fields need the settings capability');
    }

    public function testCanonicalIsOptIn(): void
    {
        $postId = $this->draft();
        \WpStub::$currentPost = $postId;
        ob_start();
        $this->plugin->frontend->canonical();
        self::assertSame('', ob_get_clean());

        add_filter('feed_item_brief_canonical', static fn (): bool => true);
        ob_start();
        $this->plugin->frontend->canonical();
        self::assertSame('<link rel="canonical" href="https://news.example.org/2026/11/14/river-bridge" />' . "\n", ob_get_clean());
    }

    public function testAttributionFunction(): void
    {
        $postId = $this->draft();
        $a = feed_item_brief_attribution($postId);
        self::assertNotNull($a);
        self::assertSame('Example News', $a->sourceName);
        self::assertNull(feed_item_brief_attribution(99999));
        self::assertSame(1, feed_item_brief_calls_today());
        self::assertSame(25, feed_item_brief_cap());
        self::assertTrue(feed_item_brief_is_enabled());
        self::assertSame(Settings::DEFAULT_CAP, feed_item_brief_cap());
    }
}
