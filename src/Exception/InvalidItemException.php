<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

/**
 * An item failed validation. The reason is a stable code: bad_url, no_title.
 */
final class InvalidItemException extends FeedItemBriefException
{
    public function __construct(private readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Invalid item: ' . $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
