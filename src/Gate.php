<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\AuthPausedException;
use IndianOpinion\FeedItemBrief\Exception\CapReachedException;
use IndianOpinion\FeedItemBrief\Exception\DisabledException;
use IndianOpinion\FeedItemBrief\Exception\SpendDeniedException;
use IndianOpinion\FeedItemBrief\Integration\SpendMeter;

/**
 * The money gate in front of every model call: kill switch, auth pause,
 * daily cap, then spend-meter (section 4.8). Read at the moment of the call.
 */
final class Gate
{
    public const PAUSE_SECONDS = 3600;

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $repository,
        private readonly Clock $clock,
        private readonly SpendMeter $spendMeter,
    ) {
    }

    /**
     * The site-local day of now, Y-m-d.
     */
    public function day(?int $time = null): string
    {
        $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        return (new \DateTimeImmutable('@' . ($time ?? $this->clock->now()->getTimestamp())))->setTimezone($zone)->format('Y-m-d');
    }

    /**
     * Unix time of the next site-local midnight.
     */
    public function nextDay(): int
    {
        $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        return $this->clock->now()->setTimezone($zone)->modify('tomorrow')->setTime(0, 0)->getTimestamp();
    }

    /**
     * Switch and pause only; takes no slot.
     *
     * @throws DisabledException
     * @throws AuthPausedException
     */
    public function check(): void
    {
        if (!$this->settings->isEnabled()) {
            throw new DisabledException();
        }
        $pause = get_option(Settings::PAUSE_OPTION, null);
        if (is_array($pause) && isset($pause['until']) && (int) $pause['until'] > $this->clock->now()->getTimestamp()) {
            $status = is_string($pause['status'] ?? null) ? $pause['status'] : Status::AUTH_FAILED;
            throw new AuthPausedException($status, (int) $pause['until']);
        }
    }

    /**
     * Take one call slot. Throws when the call must not happen.
     *
     * @throws DisabledException
     * @throws AuthPausedException
     * @throws CapReachedException
     * @throws SpendDeniedException
     */
    public function acquire(bool $ignoreCap = false): Reservation
    {
        $this->check();
        $day = $this->day();
        $cap = $ignoreCap ? PHP_INT_MAX : $this->settings->cap();
        try {
            $taken = $this->repository->takeCall($day, $cap);
        } catch (\Throwable) {
            $taken = false;
        }
        if (!$taken) {
            Settings::setState('cap_reached', ['day' => $day, 'time' => $this->clock->now()->getTimestamp()]);
            throw new CapReachedException();
        }
        if ($this->spendMeter->present()) {
            $decision = $this->spendMeter->preSpend();
            if (!$decision['allowed']) {
                $this->repository->refundCall($day);
                throw new SpendDeniedException();
            }

            return new Reservation($day, $decision['id'], true);
        }

        return new Reservation($day, null, false);
    }

    /**
     * Give back a slot for a call that was never made.
     */
    public function refund(Reservation $reservation): void
    {
        if ($reservation->settled) {
            return;
        }
        $reservation->settled = true;
        $this->repository->refundCall($reservation->day);
        if ($reservation->spendMeter) {
            $this->spendMeter->release($reservation->spendId);
        }
    }

    /**
     * After the call: record usage, or release when it never reached the provider.
     */
    public function settle(Reservation $reservation, bool $reachedProvider, ?int $promptTokens = null, ?int $completionTokens = null): void
    {
        if ($reservation->settled) {
            return;
        }
        $reservation->settled = true;
        if (!$reservation->spendMeter) {
            return;
        }
        if ($reachedProvider) {
            $this->spendMeter->record($reservation->spendId, $promptTokens, $completionTokens);
        } else {
            $this->spendMeter->release($reservation->spendId);
        }
    }

    /**
     * Pause every model call for one hour (case 39).
     */
    public function pause(string $status, string $message): int
    {
        $until = $this->clock->now()->getTimestamp() + self::PAUSE_SECONDS;
        update_option(Settings::PAUSE_OPTION, ['until' => $until, 'status' => $status, 'message' => $message], false);

        return $until;
    }

    public function callsToday(): int
    {
        try {
            return $this->repository->callsOn($this->day());
        } catch (\Throwable) {
            return 0;
        }
    }
}
