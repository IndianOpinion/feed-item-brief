<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Integration;

/**
 * Optional integration with the spend-meter plugin through its public hooks
 * (case 59), contract version 1: the `spend_meter_pre_spend` filter with an
 * intent array, then exactly one `spend_meter_record` or
 * `spend_meter_release` action for a reservation. A `null` answer means no
 * meter is listening. Any error or unknown answer is a deny: closed on money.
 */
class SpendMeter
{
    /** Contract versions of SPEND_METER_API this plugin understands. */
    public const SUPPORTED = [1];

    public const PURPOSE = 'feed-item-brief.summarise';
    public const CALLER = 'feed-item-brief';

    /** Model id sent when no model is configured (a site summariser runs). */
    public const UNKNOWN_MODEL = 'site-summariser';

    public function present(): bool
    {
        if (!defined('SPEND_METER_API')) {
            return false;
        }
        $version = constant('SPEND_METER_API');

        return is_numeric($version) && in_array((int) $version, self::SUPPORTED, true);
    }

    /**
     * The intent array of spend-meter's pre-spend filter.
     *
     * @return array{purpose: string, caller: string, provider: string, model: string, est_input_tokens: int, max_output_tokens: int}
     */
    public static function intent(string $provider, string $model, int $estInputTokens, int $maxOutputTokens): array
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $provider) !== 1) {
            $provider = 'unknown';
        }
        $model = trim($model);

        return [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model !== '' ? mb_substr($model, 0, 128, 'UTF-8') : self::UNKNOWN_MODEL,
            'est_input_tokens' => max(0, $estInputTokens),
            'max_output_tokens' => max(1, $maxOutputTokens),
        ];
    }

    /**
     * Ask before a call. `null` from the filter means no meter answered, so
     * the plugin's own cap and switch, already checked, are the guard.
     *
     * @param array<string, mixed> $intent from intent()
     * @return array{allowed: bool, id: string|null}
     */
    public function preSpend(array $intent): array
    {
        $deny = ['allowed' => false, 'id' => null];
        try {
            $decision = apply_filters('spend_meter_pre_spend', null, $intent);
        } catch (\Throwable) {
            return $deny;
        }
        if ($decision === null) {
            return ['allowed' => true, 'id' => null];
        }
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            return $deny;
        }
        $id = $decision['reservation_id'] ?? null;

        return ['allowed' => true, 'id' => is_string($id) && $id !== '' ? $id : null];
    }

    /**
     * After a call that reached the provider.
     *
     * @param array<string, mixed> $intent from intent()
     * @param string $status ok, error_billed or error_unbilled
     */
    public function record(?string $id, array $intent, string $status, ?int $inputTokens, ?int $outputTokens, ?int $httpStatus = null): void
    {
        $call = [
            'purpose' => $intent['purpose'] ?? self::PURPOSE,
            'caller' => $intent['caller'] ?? self::CALLER,
            'provider' => $intent['provider'] ?? 'unknown',
            'model' => $intent['model'] ?? self::UNKNOWN_MODEL,
            'status' => in_array($status, ['ok', 'error_billed', 'error_unbilled'], true) ? $status : 'error_billed',
            'input_tokens' => max(0, (int) $inputTokens),
            'output_tokens' => max(0, (int) $outputTokens),
        ];
        if ($id !== null) {
            $call['reservation_id'] = $id;
        }
        if ($httpStatus !== null) {
            $call['http_status'] = $httpStatus;
        }
        try {
            do_action('spend_meter_record', $call);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] spend_meter_record failed: ' . get_class($e));
        }
    }

    /**
     * Give up a reservation for a call that never reached the provider.
     */
    public function release(?string $id): void
    {
        if ($id === null || $id === '') {
            return;
        }
        try {
            do_action('spend_meter_release', $id);
        } catch (\Throwable $e) {
            error_log('[feed-item-brief] spend_meter_release failed: ' . get_class($e));
        }
    }
}
