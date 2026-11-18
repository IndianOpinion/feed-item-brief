<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Settings, constants and defaults (section 7). Constants win over settings.
 */
final class Settings
{
    public const OPTION = 'feed_item_brief_settings';
    public const SWITCH_OPTION = 'feed_item_brief_switch';
    public const PAUSE_OPTION = 'feed_item_brief_pause';
    public const STATE_OPTION = 'feed_item_brief_state';
    public const DEFAULT_CAP = 25;

    public const SPONSORED = [
        'sponsored', 'partner content', 'advertorial', 'paid post', 'promoted', 'brand studio',
        'press release', 'media release',
    ];

    public const PAYWALL = [
        'subscribe to read', 'subscribe to continue', 'for subscribers', 'premium story',
        'sign in to read', 'log in to continue', 'this article is for subscribers',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'provider' => 'openrouter',
            'base_url' => '',
            'model' => '',
            'api_key' => '',
            'post_type' => 'post',
            'post_status' => 'draft',
            'author' => 0,
            'title_source' => 'headline',
            'paywall_mode' => 'link_only',
            'no_body_mode' => 'skip',
            'fetch_pages' => false,
            'max_age_hours' => 72,
            'timeout' => 45,
            'disclosure_on' => true,
            'disclosure_text' => 'This summary was drafted with the help of software and checked by an editor.',
            'use_gen_queue' => true,
            'batch_size' => 5,
            'cron_minutes' => 5,
            'sponsored_phrases' => self::SPONSORED,
            'paywall_phrases' => self::PAYWALL,
            'error_log' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);
        if (is_string($value)) {
            $value = preg_split('/\r?\n/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $current = get_option(self::OPTION, []);
        $merged = array_merge(is_array($current) ? $current : [], $values);
        update_option(self::OPTION, $merged, false);
    }

    /**
     * The kill switch, read fresh every time (case 56). Off when the
     * constant says so, when the option cannot be read, when the schema is
     * broken, or when a filter turns it off.
     */
    public function isEnabled(): bool
    {
        if (defined('FEED_ITEM_BRIEF_DISABLED') && constant('FEED_ITEM_BRIEF_DISABLED')) {
            return false;
        }
        if (Installer::schemaError() !== '') {
            return false;
        }
        $switch = get_option(self::SWITCH_OPTION, null);
        if (!is_array($switch) || !isset($switch['enabled']) || $switch['enabled'] !== true) {
            return false;
        }

        return apply_filters('feed_item_brief_enabled', true) === true;
    }

    public function setEnabled(bool $on): void
    {
        $switch = get_option(self::SWITCH_OPTION, []);
        $switch = is_array($switch) ? $switch : [];
        $switch['enabled'] = $on;
        $switch['cap'] = isset($switch['cap']) && is_numeric($switch['cap']) ? (int) $switch['cap'] : self::DEFAULT_CAP;
        update_option(self::SWITCH_OPTION, $switch, true);
    }

    public function capFromConstant(): bool
    {
        return defined('FEED_ITEM_BRIEF_DAILY_CAP');
    }

    /**
     * Daily cap on model calls (case 57). 0 means no calls. Unreadable means 0.
     */
    public function cap(): int
    {
        if ($this->capFromConstant()) {
            $value = constant('FEED_ITEM_BRIEF_DAILY_CAP');

            return is_numeric($value) ? max(0, (int) $value) : 0;
        }
        $switch = get_option(self::SWITCH_OPTION, null);
        if (!is_array($switch)) {
            return 0;
        }

        return isset($switch['cap']) && is_numeric($switch['cap']) ? max(0, (int) $switch['cap']) : self::DEFAULT_CAP;
    }

    public function setCap(int $cap): void
    {
        $switch = get_option(self::SWITCH_OPTION, []);
        $switch = is_array($switch) ? $switch : [];
        $switch['enabled'] = ($switch['enabled'] ?? false) === true;
        $switch['cap'] = max(0, $cap);
        update_option(self::SWITCH_OPTION, $switch, true);
    }

    public function apiKeyFromConstant(): bool
    {
        return defined('FEED_ITEM_BRIEF_API_KEY') && is_string(constant('FEED_ITEM_BRIEF_API_KEY')) && constant('FEED_ITEM_BRIEF_API_KEY') !== '';
    }

    public function apiKey(): string
    {
        if ($this->apiKeyFromConstant()) {
            return (string) constant('FEED_ITEM_BRIEF_API_KEY');
        }

        return $this->string('api_key');
    }

    public function modelFromConstant(): bool
    {
        return defined('FEED_ITEM_BRIEF_MODEL') && is_string(constant('FEED_ITEM_BRIEF_MODEL')) && constant('FEED_ITEM_BRIEF_MODEL') !== '';
    }

    public function model(): string
    {
        if ($this->modelFromConstant()) {
            return (string) constant('FEED_ITEM_BRIEF_MODEL');
        }

        return $this->string('model');
    }

    public function postStatus(): string
    {
        return $this->string('post_status') === 'pending' ? 'pending' : 'draft';
    }

    public function paywallMode(): string
    {
        $mode = $this->string('paywall_mode');

        return in_array($mode, ['teaser', 'link_only', 'skip'], true) ? $mode : 'link_only';
    }

    public function noBodyMode(): string
    {
        return $this->string('no_body_mode') === 'link_only' ? 'link_only' : 'skip';
    }

    /**
     * Maximum age in seconds, 0 for none (case 28).
     */
    public function maxAge(): int
    {
        $hours = max(0, $this->int('max_age_hours'));
        $seconds = (int) apply_filters('feed_item_brief_max_age', $hours * 3600);

        return max(0, $seconds);
    }

    public function fetchPages(): bool
    {
        return $this->bool('fetch_pages');
    }

    /**
     * The disclosure line, or '' when off.
     */
    public function disclosure(): string
    {
        if (!$this->bool('disclosure_on')) {
            return '';
        }
        $text = trim($this->string('disclosure_text'));
        if ($text === '') {
            $text = (string) self::defaults()['disclosure_text'];
        }
        $filtered = apply_filters('feed_item_brief_disclosure_text', $text);

        return is_string($filtered) && trim($filtered) !== '' ? trim($filtered) : $text;
    }

    /**
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    public static function setState(string $key, mixed $value): void
    {
        $state = self::state();
        $state[$key] = $value;
        update_option(self::STATE_OPTION, $state, false);
    }

    public static function capability(): string
    {
        $cap = apply_filters('feed_item_brief_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public static function makeCapability(): string
    {
        $cap = apply_filters('feed_item_brief_make_capability', 'edit_others_posts');

        return is_string($cap) && $cap !== '' ? $cap : 'edit_others_posts';
    }
}
