<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

final class DisabledException extends FeedItemBriefException
{
    public function __construct(string $message = 'Feed Item Brief is switched off.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
