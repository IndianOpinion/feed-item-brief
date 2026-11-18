<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Optional one line per final status in the PHP error log (section 11).
 */
class Log
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function final(string $status, int $recordId, string $url, string $reason = ''): void
    {
        if (!$this->settings->bool('error_log')) {
            return;
        }
        error_log(sprintf('[feed-item-brief] record %d %s%s %s', $recordId, $status, $reason !== '' ? ' (' . $reason . ')' : '', $url));
    }

    public function error(string $message): void
    {
        error_log('[feed-item-brief] ' . $message);
    }
}
