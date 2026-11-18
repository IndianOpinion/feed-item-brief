<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Source of the current time, so tests can freeze it.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
