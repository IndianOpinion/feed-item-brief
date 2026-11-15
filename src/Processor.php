<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\AuthPausedException;
use IndianOpinion\FeedItemBrief\Exception\CapReachedException;
use IndianOpinion\FeedItemBrief\Exception\CheckFailedException;
use IndianOpinion\FeedItemBrief\Exception\DisabledException;
use IndianOpinion\FeedItemBrief\Exception\InvalidItemException;
use IndianOpinion\FeedItemBrief\Exception\NoSummariserException;
use IndianOpinion\FeedItemBrief\Exception\ProviderException;
use IndianOpinion\FeedItemBrief\Exception\SpendDeniedException;
use IndianOpinion\FeedItemBrief\Exception\StorageException;

/**
 * Processes queued records (section 4.7): page, date, class, model, draft.
 */
final class Processor
{
    public const LEASE_SECONDS = 300;
    public const BACKOFF = [300, 1800, 7200];
    public const MAX_ATTEMPTS = 3;
    public const MAX_INSERT_TRIES = 2;
    public const RETRY_LATER = 3600;

    public function __construct(
        private readonly Repository $repository,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Classifier $classifier,
        private readonly DateResolver $resolver,
        private readonly Summariser $summariser,
        private readonly Drafter $drafter,
        private readonly PageFetcher $fetcher,
        private readonly Log $log,
    ) {
    }

    /**
     * One queue run: up to $limit records within $budget seconds. Stops early
     * when the budget is spent, the cap is reached or the switch is off.
     *
     * @return array{processed: int, stopped: string}
     */
    public function run(?int $limit = null, ?float $budget = null, bool $ignoreCap = false): array
    {
        $limit ??= max(1, (int) apply_filters('feed_item_brief_batch_size', max(1, $this->settings->int('batch_size'))));
        $budget ??= (float) apply_filters('feed_item_brief_run_budget', 20.0);
        Settings::setState('last_run', $this->clock->now()->getTimestamp());
        if (!$this->settings->isEnabled()) {
            return ['processed' => 0, 'stopped' => 'disabled'];
        }
        $start = microtime(true);
        $processed = 0;
        $stopped = 'empty';
        $token = self::token();
        while ($processed < $limit) {
            if (microtime(true) - $start >= $budget) {
                $stopped = 'budget';
                break;
            }
            $record = $this->repository->claimNext($this->clock->now()->getTimestamp(), $token, self::LEASE_SECONDS);
            if ($record === null) {
                $stopped = 'empty';
                break;
            }
            $status = $this->process($record, $ignoreCap);
            $processed++;
            if ($status === Status::CAPPED) {
                $stopped = 'capped';
                break;
            }
            if ($status === Status::PAUSED) {
                $stopped = 'disabled';
                break;
            }
            if (in_array($status, [Status::AUTH_FAILED, Status::NO_CREDIT], true)) {
                $stopped = $status;
                break;
            }
            $stopped = $processed >= $limit ? 'limit' : 'empty';
        }

        return ['processed' => $processed, 'stopped' => $stopped];
    }

    /**
     * Claim and process one record now (manual, WP-CLI, gen-queue job).
     */
    public function processNow(int $id, bool $ignoreCap = false): ?string
    {
        $record = $this->repository->claim($id, $this->clock->now()->getTimestamp(), self::token(), self::LEASE_SECONDS);

        return $record === null ? null : $this->process($record, $ignoreCap);
    }

    /**
     * Process one claimed record. Returns the status it ends in.
     */
    public function process(Record $record, bool $ignoreCap = false): string
    {
        try {
            return $this->pipeline($record, $ignoreCap);
        } catch (\Throwable $e) {
            $this->log->error('record ' . $record->id . ': ' . $e->getMessage());
            try {
                $this->repository->update($record->id, [
                    'lease_token' => null,
                    'lease_expires' => null,
                    'not_before' => $this->now() + self::RETRY_LATER,
                    'reason' => 'error',
                    'updated_at' => $this->now(),
                ]);
            } catch (\Throwable) {
                // The lease expires by itself.
            }

            return $record->status;
        }
    }

    private function pipeline(Record $record, bool $ignoreCap): string
    {
        $now = $this->now();
        if (!$this->settings->isEnabled()) {
            return $this->requeue($record, Status::PAUSED, 'switched_off', $now);
        }

        try {
            $item = $record->item();
        } catch (InvalidItemException $e) {
            return $this->finish($record, Status::REJECTED, $e->reason());
        }

        $fields = [];
        $date = $record->resolvedDate() ?? new ResolvedDate($record->createdAt, ResolvedDate::FIRST_SEEN);
        $timezone = is_string($record->meta['timezone'] ?? null) ? $record->meta['timezone'] : null;
        $page = null;
        $meta = $record->meta;
        if ($record->result === null && $this->settings->fetchPages()) {
            $fetched = $this->fetcher->fetch($item->url());
            $page = $fetched['page'];
            $meta['page'] = $fetched['reason'];
            if ($page !== null) {
                $item = $this->applyPage($item, $page, $fields);
                $date = $this->resolver->refineWithPage($date, $page, $this->clock->now(), $timezone);
            }
        }
        if ($item->title() === '') {
            return $this->finish($record, Status::REJECTED, 'no_title', $fields + ['meta' => $meta]);
        }
        if ($item->language() === null) {
            $item = $item->withLanguage($page !== null && $page->language !== '' ? $page->language : null);
        }
        $fields += [
            'resolved_at' => $date->time(),
            'date_reason' => $date->stored(),
            'language' => $item->language() ?? 'und',
            'title' => $item->title(),
            'meta' => $meta,
        ];

        $ignoreAge = ($record->meta['ignore_age'] ?? false) === true;
        if (!$ignoreAge && $this->isStale($date, $now)) {
            return $this->finish($record, Status::STALE, 'stale', $fields);
        }

        $class = $this->classifier->classify($item, $page);
        $override = apply_filters('feed_item_brief_classify', $class, $item);
        if ($override instanceof BodyClass) {
            $class = $override;
        }
        $fields['body_class'] = $class->kind;
        $fields['mode'] = $class->action;

        if ($class->isSkip()) {
            return $this->finish($record, $class->kind, $class->reason, $fields);
        }

        $summary = null;
        if ($class->isSummarise()) {
            $summary = $record->result !== null ? Summary::fromArray($record->result) : null;
            if ($summary === null) {
                if (!$ignoreAge && $this->isStale($date, $this->now())) {
                    return $this->finish($record, Status::STALE, 'stale', $fields);
                }
                $outcome = $this->summarise($record, $item, $class, $date, $ignoreCap, $fields);
                if (is_string($outcome)) {
                    return $outcome;
                }
                $summary = $outcome;
                $fields['calls'] = $record->calls + $this->summariser->calls();
                if ($summary->insufficient) {
                    return $this->finish($record, Status::THIN, 'insufficient', $fields);
                }
                $fields['result'] = $summary->toArray();
            }
        }

        $refreshed = $this->repository->find($record->id) ?? $record;
        try {
            $postId = $this->drafter->draft($refreshed, $item, $date, $class, $summary);
        } catch (StorageException $e) {
            $tries = (int) ($meta['insert_tries'] ?? 0) + 1;
            $meta['insert_tries'] = $tries;
            $fields['meta'] = $meta;
            $fields['failures'] = [$e->getMessage()];
            if ($tries >= self::MAX_INSERT_TRIES) {
                return $this->finish($record, Status::INSERT_FAILED, 'insert_failed', $fields);
            }

            return $this->requeue($record, Status::INSERT_FAILED, 'insert_failed', $this->now() + 60, $fields);
        }

        $fields['post_id'] = $postId;
        $fields['failures'] = [];
        $status = $this->finish($record, Status::DRAFTED, $class->isLinkOnly() ? 'link_only' : 'summary', $fields, false);
        $final = $this->repository->find($record->id) ?? $record;
        do_action('feed_item_brief_drafted', $postId, $final);

        return $status;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function summarise(Record $record, Item $item, BodyClass $class, ResolvedDate $date, bool $ignoreCap, array $fields): Summary|string
    {
        $calls = static fn (Summariser $s): int => $record->calls + $s->calls();
        try {
            return $this->summariser->summarise($item, $class, $date, $ignoreCap);
        } catch (DisabledException) {
            return $this->requeue($record, Status::PAUSED, 'switched_off', $this->now(), $fields + ['calls' => $calls($this->summariser)]);
        } catch (CapReachedException) {
            $fields['calls'] = $calls($this->summariser);

            return $this->requeue($record, Status::CAPPED, 'cap_reached', $this->nextDay(), $fields);
        } catch (SpendDeniedException) {
            $fields['calls'] = $calls($this->summariser);

            return $this->requeue($record, Status::SPEND_DENIED, 'spend_denied', $this->nextDay(), $fields);
        } catch (AuthPausedException $e) {
            $fields['calls'] = $calls($this->summariser);

            return $this->requeue($record, $e->status(), $e->status(), $e->until(), $fields);
        } catch (NoSummariserException) {
            Settings::setState('no_summariser', $this->now());

            return $this->requeue($record, Status::QUEUED, 'no_summariser', $this->now() + self::RETRY_LATER, $fields);
        } catch (CheckFailedException $e) {
            $fields['calls'] = $calls($this->summariser);
            $fields['failures'] = $e->failures();

            return $this->finish($record, Status::CHECK_FAILED, 'check_failed', $fields);
        } catch (ProviderException $e) {
            $fields['calls'] = $calls($this->summariser);
            $fields['failures'] = [$e->getMessage()];
            if ($e->kind() === ProviderException::AUTH) {
                return $this->requeue($record, Status::AUTH_FAILED, 'auth_failed', $this->now() + Gate::PAUSE_SECONDS, $fields);
            }
            if ($e->kind() === ProviderException::CREDIT) {
                return $this->requeue($record, Status::NO_CREDIT, 'no_credit', $this->now() + Gate::PAUSE_SECONDS, $fields);
            }
            if ($e->kind() === ProviderException::CONFIG) {
                Settings::setState('not_configured', $this->now());

                return $this->requeue($record, Status::QUEUED, 'not_configured', $this->now() + self::RETRY_LATER, $fields);
            }
            $attempts = $record->attempts + 1;
            $fields['attempts'] = $attempts;
            if ($attempts >= self::MAX_ATTEMPTS) {
                return $this->finish($record, Status::PROVIDER_FAILED, 'provider_error', $fields);
            }

            return $this->requeue($record, Status::QUEUED, 'provider_error', $this->now() + self::BACKOFF[$attempts - 1], $fields);
        }
    }

    /**
     * Fill gaps from the page and follow a cross-host redirect only when the
     * final page declares a canonical on that host (case 8, 70).
     *
     * @param array<string, mixed> $fields
     */
    private function applyPage(Item $item, PageMeta $page, array &$fields): Item
    {
        if ($page->finalUrl !== '' && $page->finalUrl !== $item->url() && !Url::sameSite($page->finalUrl, $item->url())) {
            if ($page->canonical !== '' && Url::isValid($page->canonical) && Url::sameSite($page->canonical, $page->finalUrl)) {
                $item = $item->withUrl($page->canonical);
                $tracking = apply_filters('feed_item_brief_tracking_params', Url::TRACKING);
                $tracking = array_values(array_filter(is_array($tracking) ? $tracking : Url::TRACKING, 'is_string'));
                $fields['url'] = $item->url();
                $fields['key_hash'] = hash('sha256', Url::articleKey($item->url(), $tracking));
                $fields['source_name'] = $item->sourceName();
            }
        }
        if ($item->title() === '' && $page->title !== '') {
            $item = $item->withTitle($page->title);
        }

        return $item;
    }

    private function isStale(ResolvedDate $date, int $now): bool
    {
        $maxAge = $this->settings->maxAge();

        return $maxAge > 0 && $date->time() < $now - $maxAge;
    }

    /**
     * Put a record back in the queue with its lease released.
     *
     * @param array<string, mixed> $fields
     */
    private function requeue(Record $record, string $status, string $reason, int $notBefore, array $fields = []): string
    {
        $this->repository->update($record->id, $fields + [
            'status' => $status,
            'reason' => $reason,
            'not_before' => $notBefore,
            'lease_token' => null,
            'lease_expires' => null,
            'updated_at' => $this->now(),
        ]);

        return $status;
    }

    /**
     * End a record: final status, lease released, skipped action fired.
     *
     * @param array<string, mixed> $fields
     */
    private function finish(Record $record, string $status, string $reason, array $fields = [], bool $skipped = true): string
    {
        $now = $this->now();
        $this->repository->update($record->id, array_merge($fields, [
            'status' => $status,
            'reason' => $reason,
            'lease_token' => null,
            'lease_expires' => null,
            'final_at' => $now,
            'updated_at' => $now,
        ]));
        $this->log->final($status, $record->id, $record->url, $reason);
        if ($skipped) {
            $final = $this->repository->find($record->id) ?? $record;
            do_action('feed_item_brief_skipped', $final, $reason);
        }

        return $status;
    }

    /**
     * Reset a record so the queue picks it up again (log screen "Retry").
     */
    public function retry(int $id): bool
    {
        $record = $this->repository->find($id);
        if ($record === null || !in_array($record->status, Status::RETRYABLE, true)) {
            return false;
        }
        $meta = $record->meta;
        unset($meta['insert_tries']);
        $fields = [
            'status' => Status::QUEUED,
            'reason' => 'retry',
            'final_at' => null,
            'not_before' => $this->now(),
            'lease_token' => null,
            'lease_expires' => null,
            'attempts' => 0,
            'meta' => $meta,
            'updated_at' => $this->now(),
        ];
        if ($record->status === Status::STALE) {
            $meta['ignore_age'] = true;
            $fields['meta'] = $meta;
        }
        if ($record->status !== Status::INSERT_FAILED) {
            $fields['result'] = '';
        }
        $this->repository->update($id, $fields);

        return true;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function nextDay(): int
    {
        $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        return $this->clock->now()->setTimezone($zone)->modify('tomorrow')->setTime(0, 0)->getTimestamp();
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
