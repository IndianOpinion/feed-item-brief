<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

final class NoSummariserException extends FeedItemBriefException
{
    public function __construct(string $message = 'No summariser is available: install llm-json-php or hook feed_item_brief_summarise.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
