<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

/**
 * Model calls are paused after an authentication or credit error.
 */
final class AuthPausedException extends FeedItemBriefException
{
    public function __construct(private readonly string $status, private readonly int $until)
    {
        parent::__construct('Model calls are paused until ' . gmdate('c', $until) . ' (' . $status . ').');
    }

    /** auth_failed or no_credit. */
    public function status(): string
    {
        return $this->status;
    }

    public function until(): int
    {
        return $this->until;
    }
}
