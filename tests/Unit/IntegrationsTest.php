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
    /** @var list<array<string, mixed>> */
    public array $intents = [];

    public function present(): bool
    {
        return true;
    }

    public function preSpend(array $intent): array
    {
        $this->log[] = 'pre';
        $this->intents[] = $intent;

        return array_shift($this->decisions) ?? ['allowed' => false, 'id' => null];
    }

    public function record(?string $id, array $intent, string $status, ?int $inputTokens, ?int $outputTokens, ?int $httpStatus = null): void
    {
        $this->log[] = 'record:' . $id . ':' . $status . ':' . $inputTokens . ':' . $outputTokens;
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

        $this->plugin->init();
        do_action('gen_queue_register_types');
        self::assertArrayHasKey(GenQueue::JOB_TYPE, $GLOBALS['fib_test_types']);
        $job = new \stdClass();
        $job->payload = ['record_id' => $record->id];
        $this->model->queue(self::goodAnswer());
        $result = ($GLOBALS['fib_test_types'][GenQueue::JOB_TYPE])($job, null);
        self::assertSame(['record_id' => $record->id, 'status' => Status::DRAFTED], $result);
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
        self::assertSame(['pre', 'record:r1:ok:100:50'], $meter->log);
        self::assertSame('feed-item-brief.summarise', $meter->intents[0]['purpose']);
        self::assertSame('feed-item-brief', $meter->intents[0]['caller']);
        self::assertGreaterThan(0, $meter->intents[0]['est_input_tokens']);
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
        $intent = SpendMeter::intent('openrouter', 'some/model', 100, 1024);
        $answer = null;
        add_filter('spend_meter_pre_spend', static function (mixed $value, mixed $given) use (&$answer, $intent): mixed {
            self::assertNull($value);
            self::assertSame($intent, $given);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }, 10, 2);
        foreach (['yes', ['allowed' => 'true'], ['allowed' => 1], ['allowed' => false, 'reservation_id' => 'x'], new \WP_Error('x', 'y')] as $answer) {
            self::assertFalse($meter->preSpend($intent)['allowed'], var_export($answer, true));
        }
        $answer = new \RuntimeException('boom');
        self::assertFalse($meter->preSpend($intent)['allowed']);
        $answer = null;
        self::assertSame(['allowed' => true, 'id' => null], $meter->preSpend($intent), 'null means no meter answered');
        $answer = ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'abc'];
        self::assertSame(['allowed' => true, 'id' => 'abc'], $meter->preSpend($intent));
        self::assertFalse($meter->present(), 'needs SPEND_METER_API with a supported version');
    }

    public function testSpendMeterHooksAreTheContractOnes(): void
    {
        $meter = new SpendMeter();
        $seen = [];
        add_action('spend_meter_record', static function (mixed $call) use (&$seen): void {
            $seen[] = ['record', $call];
        });
        add_action('spend_meter_release', static function (mixed $id) use (&$seen): void {
            $seen[] = ['release', $id];
        });
        $intent = SpendMeter::intent('OpenRouter', '', 10, 1024);
        self::assertSame('openrouter', $intent['provider']);
        self::assertSame(SpendMeter::UNKNOWN_MODEL, $intent['model']);
        $meter->record('res-1', $intent, 'ok', 100, 50, 200);
        $meter->release('res-2');
        $meter->release(null);
        self::assertSame('record', $seen[0][0]);
        self::assertSame('res-1', $seen[0][1]['reservation_id']);
        self::assertSame('ok', $seen[0][1]['status']);
        self::assertSame(100, $seen[0][1]['input_tokens']);
        self::assertSame(50, $seen[0][1]['output_tokens']);
        self::assertSame('feed-item-brief', $seen[0][1]['caller']);
        self::assertSame(['release', 'res-2'], $seen[1]);
        self::assertCount(2, $seen);
    }

    public function testRequeuedJobRecordKicksTheOwnQueue(): void
    {
        $GLOBALS['fib_test_jobs'] = [];
        $this->settings(['use_gen_queue' => true]);
        $submission = $this->plugin->submit($this->item(), 'core-feed');
        $this->model->queue(new \IndianOpinion\FeedItemBrief\Exception\ProviderException('HTTP 503', 'transient', true));
        $this->plugin->handleJob(['record_id' => (int) $submission->recordId()]);
        self::assertSame(Status::QUEUED, $this->record((int) $submission->recordId())->status);
        self::assertSame(Plugin::CRON_RUN, \WpStub::$single[0][0]);
    }
}
