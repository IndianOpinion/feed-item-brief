<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Admin notices: auth or credit pause, missing summariser, broken schema,
 * a publish that was blocked, a refused correction.
 */
final class Notices
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function hooks(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    public function messages(int $now): array
    {
        $out = [];
        $user = get_current_user_id();
        $blocked = get_transient(Frontend::NOTICE_TRANSIENT . $user);
        if ($blocked !== false && current_user_can('edit_posts')) {
            delete_transient(Frontend::NOTICE_TRANSIENT . $user);
            $out[] = ['type' => 'error', 'text' => __('This brief was returned to draft: its source attribution is missing or its source URL is not valid. Correct the attribution in the Source review panel before publishing.', 'feed-item-brief')];
        }
        $correction = get_transient(Frontend::NOTICE_TRANSIENT . 'correction_' . $user);
        if (is_string($correction) && $correction !== '') {
            delete_transient(Frontend::NOTICE_TRANSIENT . 'correction_' . $user);
            $out[] = ['type' => 'error', 'text' => $correction];
        }
        if (!current_user_can(Settings::capability())) {
            return $out;
        }
        $schema = Installer::schemaError();
        if ($schema !== '') {
            $out[] = ['type' => 'error', 'text' => sprintf(__('Feed Item Brief is switched off: the database is missing columns (%s). Deactivate and reactivate the plugin, or check database permissions.', 'feed-item-brief'), $schema)];
        }
        $pause = get_option(Settings::PAUSE_OPTION, null);
        if (is_array($pause) && (int) ($pause['until'] ?? 0) > $now) {
            $status = (string) ($pause['status'] ?? '');
            $text = $status === Status::NO_CREDIT
                ? __('Feed Item Brief paused model calls for one hour: the provider reports no credit.', 'feed-item-brief')
                : __('Feed Item Brief paused model calls for one hour: the provider rejected the API key.', 'feed-item-brief');
            $out[] = ['type' => 'error', 'text' => $text];
        }
        $state = Settings::state();
        if (is_int($state['no_summariser'] ?? null) && $state['no_summariser'] > $now - DAY_IN_SECONDS && !has_filter('feed_item_brief_summarise')) {
            $out[] = ['type' => 'warning', 'text' => __('Feed Item Brief needs a summariser: install the llm-json-php library or hook feed_item_brief_summarise. Items wait in the queue.', 'feed-item-brief')];
        }
        if (is_int($state['not_configured'] ?? null) && $state['not_configured'] > $now - DAY_IN_SECONDS && ($this->settings->model() === '' || $this->settings->apiKey() === '')) {
            $out[] = ['type' => 'warning', 'text' => __('Feed Item Brief has no model or API key configured. Items wait in the queue.', 'feed-item-brief')];
        }

        return $out;
    }

    public function render(): void
    {
        foreach ($this->messages(time()) as $message) {
            printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($message['type']), esc_html($message['text']));
        }
    }
}
