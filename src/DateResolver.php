<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Works out a trustworthy publish time (section 4.3).
 */
final class DateResolver
{
    public const AGREE_WINDOW = 21600;

    public function __construct(private readonly int $futureTolerance = 600)
    {
    }

    /**
     * @param list<int|null> $batchClaimed claimed times of every item in the same fetch
     * @param Record|int|null $earlier an earlier record of the same story, or its claimed time
     */
    public function resolve(
        Item $item,
        ?PageMeta $page,
        array $batchClaimed,
        Record|int|null $earlier,
        \DateTimeImmutable $firstSeen,
        ?string $timezone = null,
        ?\DateTimeImmutable $now = null,
    ): ResolvedDate {
        $now ??= $firstSeen;
        $notes = [];
        $claimedValue = $item->published() ?? $item->updated();
        $parsed = DateParser::parse($claimedValue, $timezone);
        $claimed = $parsed['time'];
        $feedTime = null;
        $reason = ResolvedDate::FEED;

        if ($claimed === null) {
            $notes[] = ResolvedDate::MISSING;
        } else {
            if ($parsed['assumed']) {
                $notes[] = ResolvedDate::ASSUMED_TZ;
            }
            if ($claimed > $firstSeen->getTimestamp() + $this->futureTolerance) {
                $notes[] = ResolvedDate::FUTURE;
            } elseif (self::uniform($batchClaimed, $claimed)) {
                $notes[] = ResolvedDate::UNIFORM;
            } else {
                $feedTime = $claimed;
            }
        }

        $earlierClaimed = $earlier instanceof Record ? $earlier->claimedAt : $earlier;
        if ($feedTime !== null && $earlierClaimed !== null && $earlierClaimed < $feedTime) {
            $feedTime = $earlierClaimed;
            $reason = ResolvedDate::BUMPED;
        }

        if ($feedTime !== null) {
            $base = new ResolvedDate($feedTime, $reason, $notes, $claimed);
        } else {
            $base = $this->fallback($item, $firstSeen, $timezone, $notes, $claimed);
        }

        return $page === null ? $base : $this->refineWithPage($base, $page, $now, $timezone);
    }

    /**
     * Prefer the page's own time when the feed gave none, or when the two
     * differ by more than six hours (case 25).
     */
    public function refineWithPage(ResolvedDate $base, PageMeta $page, \DateTimeImmutable $now, ?string $timezone = null): ResolvedDate
    {
        if ($page->publishedTime === '') {
            return $base;
        }
        $parsed = DateParser::parse($page->publishedTime, $timezone);
        $pageTime = $parsed['time'];
        if ($pageTime === null || $pageTime > $now->getTimestamp() + $this->futureTolerance) {
            return $base;
        }
        $fromFeed = in_array($base->reason(), [ResolvedDate::FEED, ResolvedDate::BUMPED, ResolvedDate::PAGE], true);
        if ($fromFeed && abs($pageTime - $base->time()) <= self::AGREE_WINDOW) {
            return $base;
        }

        return new ResolvedDate($pageTime, ResolvedDate::PAGE, $base->notes(), $base->claimed());
    }

    /**
     * @param list<int|null> $batch
     */
    private static function uniform(array $batch, int $claimed): bool
    {
        if (count($batch) < 3) {
            return false;
        }
        foreach ($batch as $value) {
            if ($value !== $claimed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $notes
     */
    private function fallback(Item $item, \DateTimeImmutable $firstSeen, ?string $timezone, array $notes, ?int $claimed): ResolvedDate
    {
        $day = Url::pathDate($item->url());
        if ($day !== null) {
            $zone = new \DateTimeZone('UTC');
            if ($timezone !== null && $timezone !== '') {
                try {
                    $zone = new \DateTimeZone($timezone);
                } catch (\Exception) {
                    $zone = new \DateTimeZone('UTC');
                }
            }
            $seenLocal = $firstSeen->setTimezone($zone);
            if ((int) $seenLocal->format('Y') === $day[0] && (int) $seenLocal->format('n') === $day[1] && (int) $seenLocal->format('j') === $day[2]) {
                $time = $firstSeen->getTimestamp();
            } else {
                $time = (new \DateTimeImmutable('now', $zone))->setDate($day[0], $day[1], $day[2])->setTime(12, 0)->getTimestamp();
            }

            return new ResolvedDate($time, ResolvedDate::URL, $notes, $claimed);
        }

        return new ResolvedDate($firstSeen->getTimestamp(), ResolvedDate::FIRST_SEEN, $notes, $claimed);
    }

    /**
     * The claimed time of one raw item, for batch comparison (case 23).
     */
    public static function claimedOf(mixed $raw, ?string $timezone = null): ?int
    {
        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }

        return DateParser::parse($raw, $timezone)['time'];
    }
}
