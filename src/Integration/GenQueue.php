<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Integration;

use IndianOpinion\FeedItemBrief\Settings;

/**
 * Optional integration with the gen-queue plugin (case 55), through its
 * public functions and actions only, loaded only when present:
 * `gen_queue_register_type()` on `gen_queue_register_types`, and
 * `gen_queue_enqueue()` with an idempotency key. The handler receives
 * gen-queue's job and context objects.
 */
class GenQueue
{
    public const JOB_TYPE = 'feed_item_brief.summarise';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function active(): bool
    {
        return function_exists('gen_queue_enqueue') && $this->settings->bool('use_gen_queue');
    }

    /**
     * Enqueue one job per record. False when gen-queue refused it.
     */
    public function enqueue(int $recordId, string $keyHash): bool
    {
        try {
            $result = \gen_queue_enqueue(self::JOB_TYPE, ['record_id' => $recordId], ['idempotency_key' => $keyHash]);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] gen_queue_enqueue failed: ' . get_class($e));

            return false;
        }

        return is_int($result) && $result > 0;
    }

    /**
     * Register the job type when gen-queue asks for types.
     *
     * @param callable $handler called with gen-queue's Job and JobContext
     */
    public function registerHandler(callable $handler): void
    {
        add_action('gen_queue_register_types', static function () use ($handler): void {
            if (!function_exists('gen_queue_register_type')) {
                return;
            }
            try {
                \gen_queue_register_type(self::JOB_TYPE, $handler);
            } catch (\Throwable $e) {
                error_log('[feed-item-brief] gen_queue_register_type failed: ' . get_class($e));
            }
        });
    }

    /**
     * The record id from a gen-queue job (an object with a `payload`
     * array) or from a bare payload array.
     */
    public static function recordId(mixed $job): int
    {
        $payload = is_object($job) && isset($job->payload) ? $job->payload : $job;
        if (!is_array($payload) || !is_numeric($payload['record_id'] ?? null)) {
            return 0;
        }

        return max(0, (int) $payload['record_id']);
    }
}
