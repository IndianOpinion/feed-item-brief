<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

/**
 * The summary failed the output checks twice.
 */
final class CheckFailedException extends FeedItemBriefException
{
    /**
     * @param list<string> $failures
     */
    public function __construct(private readonly array $failures)
    {
        parent::__construct('Summary failed the output checks: ' . implode('; ', $failures));
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
