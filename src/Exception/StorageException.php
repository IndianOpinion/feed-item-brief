<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

final class StorageException extends FeedItemBriefException
{
    public function __construct(string $message = 'A database operation failed.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
