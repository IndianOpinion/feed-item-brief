<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The cleaned model output and what the checks found.
 */
final class CheckResult
{
    /**
     * @param list<string> $paragraphs
     * @param list<string> $failures hard failures: retry, then reject
     * @param list<string> $flags soft findings shown in the review panel
     */
    public function __construct(
        public readonly string $headline,
        public readonly array $paragraphs,
        public readonly string $language,
        public readonly bool $insufficient,
        public readonly array $failures,
        public readonly array $flags,
        public readonly int $removedLines = 0,
    ) {
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }
}
