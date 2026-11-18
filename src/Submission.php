<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The result of a submit (section 5.1).
 */
final class Submission
{
    public function __construct(
        private readonly bool $accepted,
        private readonly string $status,
        private readonly ?string $reason = null,
        private readonly ?int $recordId = null,
        private readonly ?int $duplicateOf = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->accepted;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function recordId(): ?int
    {
        return $this->recordId;
    }

    public function duplicateOf(): ?int
    {
        return $this->duplicateOf;
    }
}
