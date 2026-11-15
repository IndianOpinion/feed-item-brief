<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * One call slot taken from the daily cap, and the spend-meter reservation
 * that goes with it, if any.
 */
final class Reservation
{
    public bool $settled = false;

    public function __construct(
        public readonly string $day,
        public readonly ?string $spendId,
        public readonly bool $spendMeter,
    ) {
    }
}
