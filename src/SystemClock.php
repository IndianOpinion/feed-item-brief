<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
