<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\InvalidItemException;

/**
 * Validates, deduplicates, dates and records submissions (sections 4.1 to
 * 4.3). Never calls the network, never inserts a post, never throws into
 * the importer's loop.
 */
final class Intake
{
    private static bool $warnedUnknown = false;

    public function __construct(
        private readonly Repository $repository,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly DateResolver $resolver,
        private readonly Log $log,
    ) {
    }

    /**
     * Submit the items of one fetch together, so a feed that stamps every
     * item with its build time is caught (case 23, 65).
     *
     * @param array<mixed> $items
     * @return list<Submission>
     */
    public function submitBatch(array $items, string $adapterId, string $sourceKey = ''): array
    {
        $timezone = $this->timezoneFor($adapterId, $sourceKey);
        $claimed = [];
        foreach ($items as $raw) {
            $claimed[] = is_array($raw) ? DateResolver::claimedOf($raw['published'] ?? $raw['updated'] ?? null, $timezone) : null;
        }
        $out = [];
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if ($sourceKey !== '' && !isset($raw['source_key'])) {
                $raw['source_key'] = $sourceKey;
            }
            $out[] = $this->submit($raw, $adapterId, $claimed);
        }

        return $out;
    }

    /**
     * Submit one item (case 1).
     *
     * @param array<mixed> $raw
     * @param list<int|null> $batchClaimed claimed times of the whole fetch, for case 23
     */
    public function submit(array $raw, string $adapterId, array $batchClaimed = []): Submission
    {
        try {
            return $this->doSubmit($raw, $this->adapterId($adapterId), $batchClaimed);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] submission failed: ' . $e->getMessage());

            return new Submission(false, 'failed', 'storage');
        }
    }

    /**
     * @param array<mixed> $raw
     * @param list<int|null> $batchClaimed
     */
    private function doSubmit(array $raw, string $adapter, array $batchClaimed): Submission
    {
        $now = $this->clock->now()->getTimestamp();
        try {
            $item = Item::fromArray($raw, $this->settings->fetchPages());
        } catch (InvalidItemException $e) {
            return $this->reject($raw, $adapter, $e->reason(), $now);
        }

        $filtered = apply_filters('feed_item_brief_resolve_url', $item->url(), $item);
        if (is_string($filtered) && $filtered !== $item->url() && Url::isValid(Url::clean($filtered))) {
            $item = $item->withUrl(Url::clean($filtered));
        }

        if (Url::sameSite($item->url(), (string) home_url('/'))) {
            return $this->reject($raw, $adapter, 'self', $now, $item);
        }

        $tracking = apply_filters('feed_item_brief_tracking_params', Url::TRACKING);
        $tracking = array_values(array_filter(is_array($tracking) ? $tracking : Url::TRACKING, 'is_string'));
        $keyHash = hash('sha256', Url::articleKey($item->url(), $tracking));
        $guidHash = $item->guid() !== '' ? hash('sha256', $item->guid()) : '';
        $bodyHash = $item->bodyHash();

        $first = $this->repository->findByKey($keyHash);
        if ($first === null && $guidHash !== '') {
            $first = $this->repository->findByGuid($adapter, $guidHash);
        }

        $sourceKey = $item->sourceKey();
        $timezone = $this->timezoneFor($adapter, $sourceKey);
        $base = $this->baseRow($item, $adapter, $keyHash, $guidHash, $now, $timezone);

        if ($first !== null && ($first->postId !== null || !$first->isFinal())) {
            $seen = $this->repository->findSeen($first->id, $adapter, $sourceKey, $bodyHash);
            if ($seen !== null) {
                return new Submission(false, Status::DUPLICATE, 'seen', $seen->id, $first->id);
            }
            $changed = $first->bodyHash !== '' && $bodyHash !== $first->bodyHash && $item->bodyText() !== '';
            $status = $changed ? Status::UPDATE : Status::DUPLICATE;
            $id = $this->repository->insert($base + [
                'status' => $status,
                'reason' => $changed ? 'source_changed' : 'duplicate',
                'duplicate_of' => $first->id,
                'final_at' => $now,
                'body_text' => '',
                'body_html' => '',
            ]);
            if ($changed) {
                $this->noteUpdate($first, $now);
            }
            $this->log->final($status, $id, $item->url());

            return new Submission(true, $status, $changed ? 'source_changed' : 'duplicate', $id, $first->id);
        }

        $date = $this->resolver->resolve($item, null, $batchClaimed, $first, $this->clock->now(), $timezone);
        $row = $base + [
            'claimed_at' => $date->claimed(),
            'resolved_at' => $date->time(),
            'date_reason' => $date->stored(),
        ];

        $maxAge = $this->settings->maxAge();
        if ($maxAge > 0 && $date->time() < $now - $maxAge) {
            $id = $this->repository->insert($row + ['status' => Status::STALE, 'reason' => 'stale', 'final_at' => $now]);
            $record = $this->repository->find($id);
            if ($record !== null) {
                do_action('feed_item_brief_skipped', $record, 'stale');
            }
            $this->log->final(Status::STALE, $id, $item->url());

            return new Submission(true, Status::STALE, 'stale', $id);
        }

        $enabled = $this->settings->isEnabled();
        $id = $this->repository->insert($row + [
            'status' => $enabled ? Status::QUEUED : Status::PAUSED,
            'reason' => $enabled ? '' : 'switched_off',
        ]);

        return new Submission(true, $enabled ? Status::QUEUED : Status::PAUSED, null, $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(Item $item, string $adapter, string $keyHash, string $guidHash, int $now, ?string $timezone): array
    {
        $author = null;
        $object = $this->registry->get($adapter);
        if ($object !== null) {
            try {
                $author = $object->defaultAuthor($item->sourceKey());
            } catch (\Throwable) {
                $author = null;
            }
        }

        return [
            'adapter' => $adapter,
            'source_key' => $item->sourceKey(),
            'key_hash' => $keyHash,
            'guid_hash' => $guidHash,
            'url' => $item->url(),
            'guid' => $item->guid(),
            'feed_url' => $item->feedUrl(),
            'title' => $item->title(),
            'source_name' => $item->sourceName(),
            'byline' => $item->bylines(),
            'language' => $item->language() ?? '',
            'body_text' => $item->bodyText(),
            'body_html' => $item->bodyHtml(),
            'body_hash' => $item->bodyHash(),
            'failures' => [],
            'result' => '',
            'not_before' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'meta' => [
                'item' => array_diff_key($item->toArray(), ['body' => true, 'url' => true, 'title' => true]),
                'timezone' => $timezone,
                'author' => $author,
                'truncated' => $item->truncated(),
            ],
        ];
    }

    /**
     * Case 15: the source changed after the first record was taken.
     */
    private function noteUpdate(Record $first, int $now): void
    {
        $note = ['time' => $now, 'note' => 'The source changed after this draft was written'];
        if ($first->postId !== null) {
            $status = get_post_status($first->postId);
            if (in_array($status, ['draft', 'pending', 'auto-draft'], true)) {
                $updates = Meta::jsonList($first->postId, Meta::UPDATES);
                $updates[] = $note;
                Meta::setJsonList($first->postId, Meta::UPDATES, $updates);
            }

            return;
        }
        $meta = $first->meta;
        $updates = is_array($meta['updates'] ?? null) ? $meta['updates'] : [];
        $updates[] = $note;
        $meta['updates'] = $updates;
        $this->repository->update($first->id, ['meta' => $meta]);
    }

    /**
     * @param array<mixed> $raw
     */
    private function reject(array $raw, string $adapter, string $reason, int $now, ?Item $item = null): Submission
    {
        $text = static function (mixed $value): string {
            return is_string($value) ? mb_substr($value, 0, 2000, 'UTF-8') : '';
        };
        $id = $this->repository->insert([
            'adapter' => $adapter,
            'source_key' => substr($text($raw['source_key'] ?? ''), 0, 191),
            'url' => $item !== null ? $item->url() : $text($raw['url'] ?? ''),
            'guid' => $text($raw['guid'] ?? ''),
            'feed_url' => $text($raw['feed_url'] ?? ''),
            'title' => $item !== null ? $item->title() : Text::plainLine($text($raw['title'] ?? '')),
            'source_name' => $item !== null ? $item->sourceName() : '',
            'byline' => [],
            'failures' => [],
            'body_text' => '',
            'body_html' => '',
            'meta' => [],
            'result' => '',
            'status' => Status::REJECTED,
            'reason' => $reason,
            'not_before' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'final_at' => $now,
        ]);
        $record = $this->repository->find($id);
        if ($record !== null) {
            do_action('feed_item_brief_skipped', $record, $reason);
        }
        $this->log->final(Status::REJECTED, $id, $item !== null ? $item->url() : $text($raw['url'] ?? ''), $reason);

        return new Submission(false, Status::REJECTED, $reason, $id);
    }

    private function adapterId(string $id): string
    {
        if ($this->registry->has($id)) {
            return $id;
        }
        if (!self::$warnedUnknown) {
            self::$warnedUnknown = true;
            _doing_it_wrong(
                'feed_item_brief_submit',
                esc_html(sprintf('Adapter "%s" is not registered. The item was recorded as unknown.', $id)),
                '0.1.0'
            );
        }
        $clean = substr((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($id)), 0, 40);

        return 'unknown:' . $clean;
    }

    private function timezoneFor(string $adapterId, string $sourceKey): ?string
    {
        $adapter = $this->registry->get($adapterId);
        if ($adapter === null) {
            return null;
        }
        try {
            return $adapter->timezone($sourceKey);
        } catch (\Throwable) {
            return null;
        }
    }

    /** For tests: forget the once-per-request warning. */
    public static function resetWarning(): void
    {
        self::$warnedUnknown = false;
    }
}
