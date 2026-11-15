<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Integration;

use IndianOpinion\FeedItemBrief\Settings;

/**
 * Optional integration with the gen-queue plugin (case 55), through its
 * public function only, loaded only when present.
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
            error_log('[feed-item-brief] gen_queue_enqueue failed: ' . $e->getMessage());

            return false;
        }

        return $result !== false && $result !== null && !($result instanceof \WP_Error);
    }

    /**
     * Register the job handler with gen-queue.
     *
     * @param callable(mixed): mixed $handler
     */
    public function registerHandler(callable $handler): void
    {
        if (function_exists('gen_queue_register_handler')) {
            \gen_queue_register_handler(self::JOB_TYPE, $handler);
        }
        add_action('gen_queue_handle_' . self::JOB_TYPE, $handler);
    }
}
