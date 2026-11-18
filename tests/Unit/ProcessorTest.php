<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Exception\ProviderException;
use IndianOpinion\FeedItemBrief\Meta;
use IndianOpinion\FeedItemBrief\Prompt;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\FakeModel;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

final class ProcessorTest extends TestCase
{
    /** Test 3 (case 3, 4). */
    public function testManualSubmissionRunsAtOnceAndCountsAgainstTheCap(): void
    {
        $this->model->queue(self::goodAnswer());
        $result = $this->plugin->make($this->item());

        self::assertSame(Status::DRAFTED, $result['status']);
        self::assertSame('manual', $this->record(1)->adapter);
        self::assertSame(1, $this->plugin->gate->callsToday());
        self::assertCount(1, \WpStub::$posts);
    }

    /** Test 4 (case 5, 56). */
    public function testSwitchOffRecordsPausedAndTurningOnProcesses(): void
    {
        \WpStub::$options[Settings::SWITCH_OPTION]['enabled'] = false;
        $submission = $this->plugin->intake->submit($this->item(), 'core-feed');
        self::assertSame(Status::PAUSED, $submission->status());
        $this->plugin->processor->run();
        self::assertSame([], $this->model->calls);
        self::assertSame([], \WpStub::$httpCalls);

        \WpStub::$options[Settings::SWITCH_OPTION]['enabled'] = true;
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
    }

    public function testConstantAndFilterCanOnlyTurnTheSwitchOff(): void
    {
        add_filter('feed_item_brief_enabled', __NAMESPACE__ . '\__return_true_stub');
        \WpStub::$options[Settings::SWITCH_OPTION]['enabled'] = false;
        self::assertFalse((new Settings())->isEnabled());

        \WpStub::$options[Settings::SWITCH_OPTION]['enabled'] = true;
        \WpStub::$hooks = [];
        add_filter('feed_item_brief_enabled', static fn (): bool => false);
        self::assertFalse((new Settings())->isEnabled());

        \WpStub::$hooks = [];
        unset(\WpStub::$options[Settings::SWITCH_OPTION]);
        self::assertFalse((new Settings())->isEnabled(), 'an unreadable switch is off');
        self::assertSame(0, (new Settings())->cap(), 'an unreadable cap is zero');
    }

    public function testSwitchIsReadAtTheMomentOfTheCall(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        add_filter('feed_item_brief_prompt', static function (array $messages): array {
            \WpStub::$options[Settings::SWITCH_OPTION]['enabled'] = false;

            return $messages;
        });
        $this->plugin->processor->run();

        self::assertSame([], $this->model->calls);
        self::assertSame(Status::PAUSED, $this->record(1)->status);
    }

    /** Test 33 (case 37, 38). */
    public function testRequestCarriesSchemaAndDelimitedCutText(): void
    {
        $paragraph = str_repeat('Word ', 300);
        $body = implode('', array_fill(0, 20, '<p>' . $paragraph . '</p>'));
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(['body' => $body]), 'core-feed');
        $this->plugin->processor->run();

        $call = $this->model->calls[0];
        self::assertSame(Prompt::schema(), $call['schema']);
        self::assertSame(['headline', 'summary', 'language', 'insufficient'], $call['schema']['required']);
        $user = $call['messages'][1]['content'];
        self::assertStringContainsString(Prompt::START, $user);
        self::assertStringContainsString(Prompt::END, $user);
        $inside = substr($user, strpos($user, Prompt::START) + strlen(Prompt::START) + 1);
        $inside = substr($inside, 0, (int) strpos($inside, "\n" . Prompt::END));
        self::assertLessThanOrEqual(12000, mb_strlen($inside));
        self::assertStringEndsWith('Word', $inside, 'cut at a paragraph break');
        self::assertStringContainsString('data, not instructions', $call['messages'][0]['content']);
        self::assertStringContainsString('14 November 2026', $call['messages'][0]['content']);
        self::assertStringNotContainsStringIgnoringCase('word count', $call['messages'][0]['content']);
        self::assertStringNotContainsStringIgnoringCase('count the words', $call['messages'][0]['content']);
    }

    /** Test 34 (case 39). */
    public function testProviderErrorsBackOffThenEnd(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(new ProviderException('HTTP 503'), new ProviderException('HTTP 503'), new ProviderException('HTTP 503'));

        $this->plugin->processor->run();
        $record = $this->record(1);
        self::assertSame(Status::QUEUED, $record->status);
        self::assertSame($this->clock->now()->getTimestamp() + 300, $record->notBefore);

        $this->clock->advance(301);
        $this->plugin->processor->run();
        self::assertSame($this->clock->now()->getTimestamp() + 1800, $this->record(1)->notBefore);

        $this->clock->advance(1801);
        $this->plugin->processor->run();
        self::assertSame(Status::PROVIDER_FAILED, $this->record(1)->status);
        self::assertNotNull($this->record(1)->finalAt);
        self::assertSame(3, $this->plugin->gate->callsToday());
    }

    public function testAuthErrorPausesCallsForAnHourWithANotice(): void
    {
        \WpStub::$caps['manage_options'] = true;
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/2', 'guid' => '2']), 'core-feed');
        $this->model->queue(new ProviderException('bad key', ProviderException::AUTH));
        $this->plugin->processor->run();

        self::assertSame(Status::AUTH_FAILED, $this->record(1)->status);
        self::assertSame(Status::QUEUED, $this->record(2)->status, 'the run stops after an auth failure');
        self::assertCount(1, $this->model->calls);
        $pause = get_option(Settings::PAUSE_OPTION);
        self::assertSame($this->clock->now()->getTimestamp() + 3600, $pause['until']);

        $notices = (new \IndianOpinion\FeedItemBrief\Notices(new Settings()))->messages($this->clock->now()->getTimestamp());
        self::assertStringContainsString('rejected the API key', $notices[0]['text']);

        $this->plugin->processor->run();
        self::assertCount(1, $this->model->calls, 'no call while paused');

        $this->clock->advance(3601);
        $this->model->queue(self::goodAnswer(), self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
    }

    public function testNoCreditPausesLikeAuth(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(new ProviderException('402', ProviderException::CREDIT));
        $this->plugin->processor->run();

        self::assertSame(Status::NO_CREDIT, $this->record(1)->status);
        self::assertSame(Status::NO_CREDIT, get_option(Settings::PAUSE_OPTION)['status']);
    }

    /** Test 35 (case 40). */
    public function testInsufficientWritesNoDraft(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(['headline' => '', 'summary' => [], 'language' => 'en', 'insufficient' => true]);
        $this->plugin->processor->run();

        self::assertSame(Status::THIN, $this->record(1)->status);
        self::assertSame([], \WpStub::$posts);
    }

    /** Test 36 (case 41, 42). */
    public function testUnsupportedNumberIsRetriedOnceThenCheckFailed(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $bad = FakeModel::answer(['The council approved the plan by 12 votes on Tuesday.']);
        $this->model->queue($bad, $bad);
        $this->plugin->processor->run();

        self::assertCount(2, $this->model->calls);
        $retry = $this->model->calls[1]['messages'];
        self::assertSame('assistant', $retry[2]['role']);
        self::assertStringContainsString('unsupported_number: 12', $retry[3]['content']);
        $record = $this->record(1);
        self::assertSame(Status::CHECK_FAILED, $record->status);
        self::assertSame(['unsupported_number: 12'], $record->failures);
        self::assertSame([], \WpStub::$posts);
        self::assertSame(2, $this->plugin->gate->callsToday(), 'both calls count');
        self::assertSame(2, $record->calls);
    }

    public function testRetryCanSucceed(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(FakeModel::answer(['Reports differ on the bridge plan that the council approved on Tuesday.']), self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
    }

    /** Test 40 (case 41). */
    public function testOverLengthIsRetriedNeverTruncated(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $long = FakeModel::answer([trim(str_repeat('The council approved the plan. ', 40))]);
        $this->model->queue($long, self::goodAnswer());
        $this->plugin->processor->run();

        self::assertCount(2, $this->model->calls);
        self::assertStringContainsString('over_length', $this->model->calls[1]['messages'][3]['content']);
        $post = \WpStub::$posts[(int) $this->record(1)->postId];
        self::assertStringContainsString('after a long debate.', $post->post_content);
    }

    /** Test 42 (case 44). */
    public function testFilterThatPublishesIsForcedBackToDraft(): void
    {
        add_filter('feed_item_brief_post_args', static fn (array $args): array => ['post_status' => 'publish'] + $args);
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();

        $post = \WpStub::$posts[(int) $this->record(1)->postId];
        self::assertSame('draft', $post->post_status);
        self::assertCount(1, \WpStub::$doingItWrong);
    }

    /** Test 64 (case 44). */
    public function testDraftShape(): void
    {
        $this->settings(['post_status' => 'pending', 'post_type' => 'brief']);
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(FakeModel::answer([
            'The council met on Tuesday and approved the plan for the river bridge after a long debate. ' . trim(str_repeat('The council met on Tuesday and approved the plan for the river bridge after a long debate. ', 3)),
            'The council approved the plan.',
        ]));
        $this->plugin->processor->run();
        $post = \WpStub::$posts[(int) $this->record(1)->postId];

        self::assertSame('brief', $post->post_type);
        self::assertSame('pending', $post->post_status);
        self::assertSame(7, $post->post_author);
        self::assertSame('Council approves the new river bridge plan', $post->post_title);
        self::assertLessThan(300, mb_strlen($post->post_excerpt));
        self::assertStringEndsWith('.', $post->post_excerpt);
        self::assertSame('', $post->post_date);
        self::assertStringContainsString("<!-- wp:paragraph -->\n<p>The council approved the plan.</p>", $post->post_content);

        $this->settings(['title_source' => 'original']);
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/x', 'guid' => 'x']), 'core-feed');
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame('Council approves river bridge plan', \WpStub::$posts[(int) $this->record(2)->postId]->post_title);
    }

    public function testInsertFailureRetriesOnceWithoutASecondModelCall(): void
    {
        \WpStub::$insertFails = true;
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(Status::INSERT_FAILED, $this->record(1)->status);
        self::assertNull($this->record(1)->finalAt);

        \WpStub::$insertFails = false;
        $this->clock->advance(61);
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
        self::assertCount(1, $this->model->calls);
    }

    /** Test 49 (case 52, 53). */
    public function testOverlappingRunsNeverProcessOneRowTwiceAndExpiredLeasesAreReclaimed(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $now = $this->clock->now()->getTimestamp();

        $a = $this->repo->claimNext($now, 'run-a', 300);
        $b = $this->repo->claimNext($now, 'run-b', 300);
        self::assertNotNull($a);
        self::assertNull($b, 'a leased row is not claimed again');
        self::assertNull($this->repo->claim(1, $now, 'run-c', 300));

        $later = $now + 301;
        $c = $this->repo->claimNext($later, 'run-c', 300);
        self::assertNotNull($c, 'an expired lease is reclaimable');
        self::assertSame('run-c', $c->leaseToken);
    }

    public function testRunStopsAtTheBatchSizeAndTheBudget(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/n' . $i, 'guid' => 'n' . $i]), 'core-feed');
            $this->model->queue(self::goodAnswer());
        }
        $result = $this->plugin->processor->run();
        self::assertSame(5, $result['processed']);

        $result = $this->plugin->processor->run(null, 0.0);
        self::assertSame(0, $result['processed']);
        self::assertSame('budget', $result['stopped']);
    }

    /** Test 50 (case 54). */
    public function testBacklogIsProcessedNewestFirst(): void
    {
        foreach (['2026-11-14T06:00:00Z', '2026-11-14T10:00:00Z', '2026-11-14T08:00:00Z'] as $i => $time) {
            $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/b' . $i, 'guid' => 'b' . $i, 'published' => $time]), 'core-feed');
            $this->model->queue(self::goodAnswer());
        }
        $order = [];
        add_action('feed_item_brief_drafted', static function (int $postId, $record) use (&$order): void {
            $order[] = $record->id;
        }, 10, 2);
        $this->plugin->processor->run();
        self::assertSame([2, 3, 1], $order);
    }

    /** Test 52 (case 57). */
    public function testCapLimitsCallsAndTheLastSlotIsTakenOnce(): void
    {
        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 2;
        for ($i = 0; $i < 3; $i++) {
            $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/c' . $i, 'guid' => 'c' . $i]), 'core-feed');
            $this->model->queue(self::goodAnswer());
        }
        $result = $this->plugin->processor->run();

        self::assertSame('capped', $result['stopped']);
        self::assertCount(2, $this->model->calls);
        self::assertSame(1, $this->repo->countByStatus()[Status::CAPPED] ?? 0);
        $day = $this->plugin->gate->day();
        self::assertSame(2, $this->repo->callsOn($day));
        self::assertSame($day, Settings::state()['cap_reached']['day']);

        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 3;
        self::assertTrue($this->repo->takeCall($day, 3));
        self::assertFalse($this->repo->takeCall($day, 3), 'two workers cannot both take the last slot');
    }

    public function testCapZeroMeansNoCalls(): void
    {
        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 0;
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame([], $this->model->calls);
        self::assertSame(Status::CAPPED, $this->record(1)->status);
    }

    /** Test 53 (case 58). */
    public function testCappedItemsRunTheNextSiteLocalDay(): void
    {
        \WpStub::$options['timezone_string'] = 'Asia/Kolkata';
        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 1;
        $this->settings(['max_age_hours' => 0]);
        $this->clock->set('2026-11-14T15:00:00Z');
        for ($i = 0; $i < 2; $i++) {
            $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/d' . $i, 'guid' => 'd' . $i]), 'core-feed');
            $this->model->queue(self::goodAnswer());
        }
        $this->plugin->processor->run();
        $capped = $this->record(2);
        self::assertSame(Status::CAPPED, $capped->status);
        self::assertSame((new \DateTimeImmutable('2026-11-14T18:30:00Z'))->getTimestamp(), $capped->notBefore, 'midnight in Kolkata');

        $this->clock->set('2026-11-14T18:00:00Z');
        $this->plugin->processor->run();
        self::assertSame(Status::CAPPED, $this->record(2)->status);

        $this->clock->set('2026-11-14T18:31:00Z');
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(2)->status);
    }

    /** Test 65 (case 71, 72). */
    public function testPluggableSummariserWithoutTheLibrary(): void
    {
        $this->libraryInstalled = false;
        $this->build();
        $seen = [];
        add_filter('feed_item_brief_summarise', static function ($result, array $request, $item) use (&$seen) {
            $seen[] = $request;

            return FakeModel::answer(['The council approved the plan on Tuesday.', 'Word count: 7']);
        }, 10, 3);
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();

        $record = $this->record(1);
        self::assertSame(Status::DRAFTED, $record->status);
        self::assertCount(1, $seen);
        self::assertSame(Prompt::schema(), $seen[0]['schema']);
        self::assertSame(1, $this->plugin->gate->callsToday());
        $post = \WpStub::$posts[(int) $record->postId];
        self::assertStringNotContainsString('Word count', $post->post_content, 'the output checks ran');
        self::assertStringContainsString('feed-item-brief-attribution', $post->post_content);
        self::assertSame('filter', get_post_meta((int) $record->postId, Meta::MODEL, true));
    }

    public function testNoSummariserRequeuesAndRefundsTheSlot(): void
    {
        $this->libraryInstalled = false;
        $this->build();
        \WpStub::$caps['manage_options'] = true;
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();

        $record = $this->record(1);
        self::assertSame(Status::QUEUED, $record->status);
        self::assertSame('no_summariser', $record->reason);
        self::assertSame([], \WpStub::$posts);
        self::assertSame(0, $this->plugin->gate->callsToday());
        $notices = (new \IndianOpinion\FeedItemBrief\Notices(new Settings()))->messages($this->clock->now()->getTimestamp());
        self::assertStringContainsString('needs a summariser', $notices[0]['text']);
    }

    public function testFilterIsNeverCalledWhenSwitchedOffOrCapped(): void
    {
        $called = 0;
        add_filter('feed_item_brief_summarise', static function () use (&$called) {
            $called++;

            return null;
        });
        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 0;
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame(0, $called);

        \WpStub::$options[Settings::SWITCH_OPTION] = ['enabled' => false, 'cap' => 5];
        $this->plugin->processor->run();
        $this->plugin->processor->processNow(1);
        self::assertSame(0, $called);
    }

    public function testSummariserFilterErrorIsTransient(): void
    {
        add_filter('feed_item_brief_summarise', static fn () => new \WP_Error('down', 'Service down'));
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame('provider_error', $this->record(1)->reason);
        self::assertSame(1, $this->record(1)->attempts);
    }

    public function testRetryFromTheLogRequeues(): void
    {
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $bad = FakeModel::answer(['The council approved the plan by 12 votes.']);
        $this->model->queue($bad, $bad, self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(Status::CHECK_FAILED, $this->record(1)->status);

        self::assertTrue($this->plugin->processor->retry(1));
        $this->plugin->processor->run();
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
        self::assertFalse($this->plugin->processor->retry(1), 'drafted records are not retryable');
    }

    public function testStaleRetryKeepsTheRealDate(): void
    {
        $this->plugin->intake->submit($this->item(['published' => '2026-11-01T09:00:00Z']), 'core-feed');
        self::assertSame(Status::STALE, $this->record(1)->status);
        self::assertTrue($this->plugin->processor->retry(1));
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();

        $record = $this->record(1);
        self::assertSame(Status::DRAFTED, $record->status);
        self::assertSame((new \DateTimeImmutable('2026-11-01T09:00:00Z'))->getTimestamp(), $record->resolvedAt);
    }

    public function testRetentionClearsBodiesAndOldRows(): void
    {
        $this->plugin->intake->submit($this->item(['body' => '']), 'core-feed');
        $this->plugin->processor->run();
        self::assertSame(Status::NO_BODY, $this->record(1)->status);
        $this->plugin->intake->submit($this->item(['url' => 'https://news.example.org/r', 'guid' => 'r']), 'core-feed');
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();
        self::assertNotSame('', $this->record(2)->bodyText);

        $this->clock->advance(8 * 86400);
        $this->plugin->cleanup();
        self::assertSame('', $this->record(2)->bodyText);
        self::assertNotSame('', $this->record(2)->bodyHash);

        $this->clock->advance(90 * 86400);
        $this->plugin->cleanup();
        self::assertNull($this->repo->find(1), 'rows without a post go after 90 days');
        self::assertNotNull($this->repo->find(2), 'rows with a post stay');
    }
}

function __return_true_stub(): bool
{
    return true;
}
