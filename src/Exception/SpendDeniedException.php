<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

final class SpendDeniedException extends FeedItemBriefException
{
    public function __construct(string $message = 'The spend meter denied the call.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
