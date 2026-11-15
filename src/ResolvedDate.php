<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The publish time the plugin trusts, why, and what the item claimed.
 */
final class ResolvedDate
{
    public const FEED = 'date_feed';
    public const PAGE = 'date_page';
    public const BUMPED = 'date_bumped';
    public const URL = 'date_url';
    public const FIRST_SEEN = 'date_first_seen';
    public const MISSING = 'date_missing';
    public const FUTURE = 'date_future';
    public const UNIFORM = 'date_uniform';
    public const ASSUMED_TZ = 'assumed_tz';

    /**
     * @param list<string> $notes problems with the claimed time
     */
    public function __construct(
        private readonly int $time,
        private readonly string $reason,
        private readonly array $notes = [],
        private readonly ?int $claimed = null,
    ) {
    }

    /**
     * Rebuild from the stored comma list.
     */
    public static function fromStored(int $time, string $codes, ?int $claimed): self
    {
        $list = array_values(array_filter(explode(',', $codes)));
        $reason = $list === [] ? self::FIRST_SEEN : (string) array_pop($list);

        return new self($time, $reason, $list, $claimed);
    }

    public function time(): int
    {
        return $this->time;
    }

    /** How the time was chosen: date_feed, date_page, date_bumped, date_url or date_first_seen. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function claimed(): ?int
    {
        return $this->claimed;
    }

    /**
     * Every code: notes first, the reason last.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique([...$this->notes, $this->reason]));
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function stored(): string
    {
        return implode(',', $this->codes());
    }

    /**
     * True when an editor should check the date (case 29).
     */
    public function needsReview(): bool
    {
        $codes = $this->codes();

        return !($codes === [self::FEED] || $codes === [self::PAGE]);
    }

    public function withNotes(string ...$notes): self
    {
        return new self($this->time, $this->reason, array_values(array_unique([...$this->notes, ...$notes])), $this->claimed);
    }
}
