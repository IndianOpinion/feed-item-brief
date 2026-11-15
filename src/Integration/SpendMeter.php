<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Integration;

/**
 * Optional integration with the spend-meter plugin through its public
 * functions (case 59). Any error or unknown answer is a deny: closed on money.
 */
class SpendMeter
{
    /** Contract versions of SPEND_METER_API this plugin understands. */
    public const SUPPORTED = [1];

    public const PURPOSE = 'feed-item-brief.summarise';
    public const CALLER = 'feed-item-brief';

    public function present(): bool
    {
        if (!defined('SPEND_METER_API') || !function_exists('spend_meter_pre_spend')) {
            return false;
        }
        $version = constant('SPEND_METER_API');

        return is_numeric($version) && in_array((int) $version, self::SUPPORTED, true);
    }

    /**
     * @return array{allowed: bool, id: string|null}
     */
    public function preSpend(): array
    {
        $deny = ['allowed' => false, 'id' => null];
        try {
            $decision = \spend_meter_pre_spend([
                'purpose' => self::PURPOSE,
                'caller' => self::CALLER,
            ]);
        } catch (\Throwable) {
            return $deny;
        }
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            return $deny;
        }
        $id = $decision['reservation_id'] ?? $decision['id'] ?? null;

        return ['allowed' => true, 'id' => is_scalar($id) ? (string) $id : null];
    }

    public function record(?string $id, ?int $promptTokens, ?int $completionTokens): void
    {
        if (!function_exists('spend_meter_record')) {
            return;
        }
        try {
            \spend_meter_record($id, [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'purpose' => self::PURPOSE,
                'caller' => self::CALLER,
            ]);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] spend_meter_record failed: ' . $e->getMessage());
        }
    }

    public function release(?string $id): void
    {
        if (!function_exists('spend_meter_release')) {
            return;
        }
        try {
            \spend_meter_release($id);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] spend_meter_release failed: ' . $e->getMessage());
        }
    }
}
