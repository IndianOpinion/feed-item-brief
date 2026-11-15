<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Support;

use IndianOpinion\FeedItemBrief\Clock;

final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-14T12:00:00Z')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $now): void
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
