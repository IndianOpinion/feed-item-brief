<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

final class CapReachedException extends FeedItemBriefException
{
    public function __construct(string $message = 'The daily model call cap is reached.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
