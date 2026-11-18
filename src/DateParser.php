<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Parses claimed times: a Unix timestamp, RFC 2822 or ISO 8601 (case 18, 20, 21).
 */
final class DateParser
{
    /** 1995-01-01T00:00:00Z. Anything earlier counts as missing. */
    public const FLOOR = 788918400;

    /**
     * @return array{time: int|null, assumed: bool} time is null when missing
     */
    public static function parse(int|string|null $value, ?string $timezone = null): array
    {
        $missing = ['time' => null, 'assumed' => false];
        if ($value === null) {
            return $missing;
        }
        if (is_int($value) || preg_match('/^-?\d{1,12}$/', trim($value)) === 1) {
            $ts = (int) $value;

            return $ts >= self::FLOOR ? ['time' => $ts, 'assumed' => false] : $missing;
        }
        $value = trim($value);
        if ($value === '') {
            return $missing;
        }
        $info = date_parse($value);
        if ($info['error_count'] > 0 || $info['year'] === false || $info['month'] === false || $info['day'] === false) {
            return $missing;
        }
        $relative = $info['relative'] ?? [];
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $unit) {
            if (!empty($relative[$unit])) {
                return $missing;
            }
        }
        $hasZone = !empty($info['is_localtime']);
        $zone = new \DateTimeZone('UTC');
        if (!$hasZone && $timezone !== null && $timezone !== '') {
            try {
                $zone = new \DateTimeZone($timezone);
            } catch (\Exception) {
                $zone = new \DateTimeZone('UTC');
            }
        }
        try {
            $time = new \DateTimeImmutable($value, $zone);
        } catch (\Exception) {
            return $missing;
        }
        $ts = $time->getTimestamp();
        if ($ts < self::FLOOR) {
            return $missing;
        }

        return ['time' => $ts, 'assumed' => !$hasZone];
    }
}
