<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Integration\GenQueue;
use IndianOpinion\FeedItemBrief\Integration\SpendMeter;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

require_once dirname(__DIR__) . '/stubs/companions.php';

/**
 * A spend meter whose answers the test scripts.
 */
final class ScriptedSpendMeter extends SpendMeter
{
    /** @var list<array{allowed: bool, id: string|null}> */
    public array $decisions = [];
    /** @var list<string> */
    public array $log = [];

    public function present(): bool
    {
        return true;
    }

    public function preSpend(): array
    {
        $this->log[] = 'pre';

        return array_shift($this->decisions) ?? ['allowed' => false, 'id' => null];
    }

    public function record(?string $id, ?int $promptTokens, ?int $completionTokens): void
    {
        $this->log[] = 'record:' . $id . ':' . $promptTokens . ':' . $completionTokens;
    }

    public function release(?string $id): void
    {
        $this->log[] = 'release:' . $id;
    }
}

final class IntegrationsTest extends TestCase
{
    /** Test 51 (case 55). */
    public function testGenQueueGetsOneJobPerItemAndTheOwnRunnerIsNotScheduled(): void
    {
        $GLOBALS['fib_test_jobs'] = [];
        $this->settings(['use_gen_queue' => true]);
        $this->plugin->schedule();
        self::assertArrayNotHasKey(Plugin::CRON_RUN, \WpStub::$scheduled);
        self::assertArrayHasKey(Plugin::CRON_CLEANUP, \WpStub::$scheduled);

        $submission = $this->plugin->submit($this->item(), 'core-feed');
        $record = $this->record((int) $submission->recordId());
        self::assertSame([[GenQueue::JOB_TYPE, ['record_id' => $record->id], ['idempotency_key' => $record->keyHash]]], $GLOBALS['fib_test_jobs']);

        $this->model->queue(self::goodAnswer());
        $this->plugin->handleJob(['record_id' => $record->id]);
        self::assertSame(Status::DRAFTED, $this->record($record->id)->status);

        $this->settings(['use_gen_queue' => false]);
        $this->plugin->schedule();
        self::assertArrayHasKey(Plugin::CRON_RUN, \WpStub::$scheduled);
    }

    public function testRefusedEnqueueFallsBackToTheOwnRunner(): void
    {
        $GLOBALS['fib_test_jobs'] = [];
        $GLOBALS['fib_test_refuse'] = true;
        $this->settings(['use_gen_queue' => true]);
        $this->plugin->submit($this->item(), 'core-feed');
        $GLOBALS['fib_test_refuse'] = false;

        self::assertSame(Plugin::CRON_RUN, \WpStub::$single[0][0]);
        self::assertSame(Status::QUEUED, $this->record(1)->status);
    }

    /** Test 54 (case 59). */
    public function testSpendMeterDenyMakesNoCallAndAllowIsSettledOnce(): void
    {
        $meter = new ScriptedSpendMeter();
        $this->spendMeter = $meter;
        $this->build();
        $meter->decisions = [['allowed' => false, 'id' => null]];
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();

        self::assertSame([], $this->model->calls);
        $record = $this->record(1);
        self::assertSame(Status::SPEND_DENIED, $record->status);
        self::assertSame(0, $this->plugin->gate->callsToday(), 'the slot is given back');
        self::assertGreaterThan($this->clock->now()->getTimestamp(), $record->notBefore);

        $this->clock->advance(86400);
        $meter->decisions = [['allowed' => true, 'id' => 'r1']];
        $meter->log = [];
        $this->model->queue(self::goodAnswer());
        $this->plugin->processor->run();
        self::assertSame(['pre', 'record:r1:100:50'], $meter->log);
        self::assertSame(Status::DRAFTED, $this->record(1)->status);
    }

    public function testSpendMeterReleaseWhenTheCallNeverReachedTheProvider(): void
    {
        $meter = new ScriptedSpendMeter();
        $this->spendMeter = $meter;
        $this->build();
        $meter->decisions = [['allowed' => true, 'id' => 'r2']];
        $this->model->queue(new \IndianOpinion\FeedItemBrief\Exception\ProviderException('timeout', 'transient', false));
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();

        self::assertSame(['pre', 'release:r2'], $meter->log);
    }

    /** Test 61 (failure table, spend-meter shape). */
    public function testUnknownSpendMeterShapeIsADeny(): void
    {
        $meter = new SpendMeter();
        foreach ([null, 'yes', ['allowed' => 'true'], ['allowed' => 1], new \WP_Error('x', 'y')] as $answer) {
            $GLOBALS['fib_test_spend'] = $answer;
            self::assertFalse($meter->preSpend()['allowed'], var_export($answer, true));
        }
        $GLOBALS['fib_test_spend'] = new \RuntimeException('boom');
        self::assertFalse($meter->preSpend()['allowed']);
        $GLOBALS['fib_test_spend'] = ['allowed' => true, 'reservation_id' => 9];
        self::assertSame(['allowed' => true, 'id' => '9'], $meter->preSpend());
        self::assertFalse($meter->present(), 'needs SPEND_METER_API with a supported version');
    }
}
