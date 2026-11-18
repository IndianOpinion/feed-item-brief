<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Post meta registration and the review panel (case 48, 50, 51). One meta
 * box serves both editors: the block editor shows it in its sidebar.
 */
final class ReviewPanel
{
    public const NONCE = 'feed_item_brief_correct';

    private bool $saving = false;

    public function __construct(
        private readonly AttributionRenderer $renderer,
        private readonly Clock $clock,
    ) {
    }

    public function hooks(): void
    {
        add_action('init', [$this, 'registerMeta']);
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_action('rest_api_init', [$this, 'restFilters']);
    }

    public function registerMeta(): void
    {
        foreach (Meta::registered() as $key => $type) {
            register_post_meta('', $key, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => [$this, 'authorize'],
            ]);
        }
    }

    /**
     * Read access needs edit_post; writes through the meta API are refused,
     * so corrections always go through the panel and its history.
     */
    public function authorize(bool $allowed, string $metaKey, int $objectId, int $userId = 0, string $cap = ''): bool
    {
        if (in_array($cap, ['edit_post_meta', 'add_post_meta', 'delete_post_meta'], true)) {
            return false;
        }

        return user_can($userId, 'edit_post', $objectId);
    }

    public function restFilters(): void
    {
        foreach (get_post_types(['show_in_rest' => true]) as $type) {
            add_filter('rest_prepare_' . $type, [$this, 'hideMeta'], 10, 2);
        }
    }

    /**
     * Strip this plugin's meta from REST responses for users who cannot
     * edit the post.
     */
    public function hideMeta(mixed $response, mixed $post): mixed
    {
        if (!$response instanceof \WP_REST_Response || !$post instanceof \WP_Post) {
            return $response;
        }
        if (current_user_can('edit_post', $post->ID)) {
            return $response;
        }
        $data = $response->get_data();
        if (is_array($data) && isset($data['meta']) && is_array($data['meta'])) {
            foreach (array_keys(Meta::registered()) as $key) {
                unset($data['meta'][$key]);
            }
            $response->set_data($data);
        }

        return $response;
    }

    public function addMetaBox(string $postType, mixed $post): void
    {
        if (!$post instanceof \WP_Post || !self::isBrief((int) $post->ID) || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        add_meta_box('feed-item-brief-review', __('Source review', 'feed-item-brief'), [$this, 'render'], $postType, 'side', 'high');
    }

    public static function isBrief(int $postId): bool
    {
        $record = get_post_meta($postId, Meta::RECORD, true);

        return is_numeric($record) && (int) $record > 0;
    }

    /**
     * The panel's content: everything an editor needs to check the draft.
     *
     * @return array<string, mixed>
     */
    public static function data(int $postId): array
    {
        $string = static function (string $key) use ($postId): string {
            $value = get_post_meta($postId, $key, true);

            return is_scalar($value) ? (string) $value : '';
        };
        $reason = $string(Meta::DATE_REASON);
        $date = ResolvedDate::fromStored((int) $string(Meta::RESOLVED), $reason, $string(Meta::CLAIMED) !== '' ? (int) $string(Meta::CLAIMED) : null);
        $flags = array_values(array_filter(Meta::jsonList($postId, Meta::FLAGS), 'is_string'));

        return [
            'source_name' => $string(Meta::SOURCE_NAME),
            'source_url' => $string(Meta::SOURCE_URL),
            'byline' => array_values(array_filter(Meta::jsonList($postId, Meta::BYLINE), 'is_string')),
            'resolved' => (int) $string(Meta::RESOLVED),
            'claimed' => $date->claimed(),
            'date_reason' => $reason,
            'date_review' => $date->needsReview(),
            'no_date' => $date->reason() === ResolvedDate::FIRST_SEEN,
            'body_class' => $string(Meta::BODY_CLASS),
            'mode' => $string(Meta::MODE),
            'flags' => $flags,
            'relative_time' => array_values(array_filter($flags, static fn (string $f): bool => str_starts_with($f, 'relative_time:'))),
            'updates' => Meta::jsonList($postId, Meta::UPDATES),
            'model' => $string(Meta::MODEL),
            'call_time' => (int) $string(Meta::CALL_TIME),
            'corrections' => Meta::jsonList($postId, Meta::CORRECTIONS),
        ];
    }

    public function render(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        $d = self::data((int) $post->ID);
        $format = (string) get_option('date_format', 'F j, Y') . ' ' . (string) get_option('time_format', 'g:i a');
        echo '<div class="feed-item-brief-review">';
        echo '<p><strong>' . esc_html__('Source', 'feed-item-brief') . ':</strong> <a href="' . esc_url((string) $d['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) $d['source_name']) . '</a></p>';
        $byline = is_array($d['byline']) ? Byline::join(array_values(array_filter($d['byline'], 'is_string'))) : '';
        echo '<p><strong>' . esc_html__('Byline', 'feed-item-brief') . ':</strong> ' . esc_html($byline !== '' ? $byline : __('none given', 'feed-item-brief')) . '</p>';
        echo '<p><strong>' . esc_html__('Published', 'feed-item-brief') . ':</strong> ' . esc_html((string) wp_date($format, (int) $d['resolved'])) . '</p>';
        if ($d['date_review'] === true) {
            $claimed = is_int($d['claimed']) ? (string) wp_date($format, $d['claimed']) : __('none', 'feed-item-brief');
            echo '<p class="feed-item-brief-date-check"><strong>' . esc_html__('Check the date', 'feed-item-brief') . ':</strong> '
                . esc_html(sprintf(__('claimed %1$s; reason %2$s', 'feed-item-brief'), $claimed, (string) $d['date_reason'])) . '</p>';
        }
        if ($d['no_date'] === true) {
            echo '<p>' . esc_html__('The source gave no date. The time shown is when the item was first seen.', 'feed-item-brief') . '</p>';
        }
        echo '<p><strong>' . esc_html__('Body', 'feed-item-brief') . ':</strong> ' . esc_html((string) $d['body_class']) . '</p>';
        foreach (is_array($d['flags']) ? $d['flags'] : [] as $flag) {
            echo '<p class="feed-item-brief-flag">' . esc_html((string) $flag) . '</p>';
        }
        foreach (is_array($d['updates']) ? $d['updates'] : [] as $update) {
            if (is_array($update)) {
                echo '<p class="feed-item-brief-update">' . esc_html((string) ($update['note'] ?? '')) . ' (' . esc_html((string) wp_date($format, (int) ($update['time'] ?? 0))) . ')</p>';
            }
        }
        if ($d['model'] !== '') {
            echo '<p><strong>' . esc_html__('Model', 'feed-item-brief') . ':</strong> ' . esc_html((string) $d['model']) . ' ' . esc_html((string) wp_date($format, (int) $d['call_time'])) . '</p>';
        }
        if (current_user_can(Settings::capability())) {
            wp_nonce_field(self::NONCE, '_feed_item_brief_nonce');
            $zone = wp_timezone();
            $local = (new \DateTimeImmutable('@' . (int) $d['resolved']))->setTimezone($zone)->format('Y-m-d H:i');
            echo '<details><summary>' . esc_html__('Correct the attribution', 'feed-item-brief') . '</summary>';
            echo '<p><label>' . esc_html__('Source name', 'feed-item-brief') . '<br><input type="text" class="widefat" name="feed_item_brief[source_name]" value="' . esc_attr((string) $d['source_name']) . '"></label></p>';
            echo '<p><label>' . esc_html__('Source URL', 'feed-item-brief') . '<br><input type="url" class="widefat" name="feed_item_brief[source_url]" value="' . esc_attr((string) $d['source_url']) . '" required></label></p>';
            echo '<p><label>' . esc_html__('Byline (one name per line)', 'feed-item-brief') . '<br><textarea class="widefat" name="feed_item_brief[byline]" rows="2">' . esc_textarea(implode("\n", is_array($d['byline']) ? $d['byline'] : [])) . '</textarea></label></p>';
            echo '<p><label>' . esc_html__('Published (site time, YYYY-MM-DD HH:MM)', 'feed-item-brief') . '<br><input type="text" class="widefat" name="feed_item_brief[published]" value="' . esc_attr($local) . '"></label></p>';
            echo '</details>';
        }
        echo '</div>';
    }

    public function save(int $postId, mixed $post = null): void
    {
        if ($this->saving || !isset($_POST['_feed_item_brief_nonce'], $_POST['feed_item_brief']) || !is_array($_POST['feed_item_brief'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_feed_item_brief_nonce'])), self::NONCE)) {
            return;
        }
        if (!current_user_can(Settings::capability()) || !current_user_can('edit_post', $postId) || !self::isBrief($postId)) {
            return;
        }
        $input = wp_unslash($_POST['feed_item_brief']);
        $values = [];
        foreach (['source_name', 'source_url', 'byline', 'published'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $values[$key] = $input[$key];
            }
        }
        $result = $this->correct($postId, $values, get_current_user_id());
        if ($result instanceof \WP_Error) {
            set_transient(Frontend::NOTICE_TRANSIENT . 'correction_' . get_current_user_id(), $result->get_error_message(), 300);
        }
    }

    /**
     * Apply a correction, record who changed what, and refresh the static
     * fallback in the content. An empty or invalid URL is refused.
     *
     * @param array<string, string> $values
     */
    public function correct(int $postId, array $values, int $userId): bool|\WP_Error
    {
        $current = self::data($postId);
        $changes = [];
        if (array_key_exists('source_url', $values)) {
            $url = Url::clean($values['source_url']);
            if (!Url::isValid($url)) {
                return new \WP_Error('feed_item_brief_bad_url', __('The source URL must be a full http or https address. The correction was not saved.', 'feed-item-brief'));
            }
            if ($url !== $current['source_url']) {
                $changes[Meta::SOURCE_URL] = ['source_url', $current['source_url'], $url];
            }
        }
        if (array_key_exists('source_name', $values)) {
            $name = sanitize_text_field($values['source_name']);
            if ($name === '') {
                return new \WP_Error('feed_item_brief_no_name', __('The source name cannot be empty.', 'feed-item-brief'));
            }
            if ($name !== $current['source_name']) {
                $changes[Meta::SOURCE_NAME] = ['source_name', $current['source_name'], $name];
            }
        }
        if (array_key_exists('byline', $values)) {
            $names = Byline::normalise(preg_split('/\r?\n/', $values['byline']) ?: []);
            if ($names !== $current['byline']) {
                $changes[Meta::BYLINE] = ['byline', $current['byline'], $names];
            }
        }
        if (array_key_exists('published', $values) && trim($values['published']) !== '') {
            $parsed = DateParser::parse($values['published'], wp_timezone_string());
            if ($parsed['time'] === null) {
                return new \WP_Error('feed_item_brief_bad_date', __('The published time could not be read.', 'feed-item-brief'));
            }
            $currentLocal = (new \DateTimeImmutable('@' . (int) $current['resolved']))->setTimezone(wp_timezone())->format('Y-m-d H:i');
            if (trim($values['published']) !== $currentLocal && $parsed['time'] !== $current['resolved']) {
                $changes[Meta::RESOLVED] = ['published', $current['resolved'], $parsed['time']];
            }
        }
        if ($changes === []) {
            return true;
        }
        $history = Meta::jsonList($postId, Meta::CORRECTIONS);
        $now = $this->clock->now()->getTimestamp();
        foreach ($changes as $key => [$field, $old, $new]) {
            if ($key === Meta::BYLINE) {
                Meta::setJsonList($postId, Meta::BYLINE, is_array($new) ? $new : []);
            } else {
                update_post_meta($postId, $key, is_string($new) ? wp_slash($new) : $new);
            }
            if ($key === Meta::RESOLVED) {
                update_post_meta($postId, Meta::DATE_REASON, 'corrected');
            }
            $history[] = ['time' => $now, 'user' => $userId, 'field' => $field, 'old' => $old, 'new' => $new];
        }
        Meta::setJsonList($postId, Meta::CORRECTIONS, $history);
        $this->refreshFallback($postId);

        return true;
    }

    /**
     * Rewrite the saved static fallback so it matches the corrected meta.
     */
    private function refreshFallback(int $postId): void
    {
        $attribution = Attribution::fromPost($postId);
        $post = get_post($postId);
        if ($attribution === null || !$post instanceof \WP_Post) {
            return;
        }
        $pattern = '/<!-- wp:' . preg_quote(AttributionRenderer::BLOCK, '/') . '\b.*?<!-- \/wp:' . preg_quote(AttributionRenderer::BLOCK, '/') . ' -->/s';
        $markup = $this->renderer->blockMarkup($attribution);
        $content = (string) $post->post_content;
        $updated = preg_match($pattern, $content) === 1
            ? (string) preg_replace_callback($pattern, static fn (): string => $markup, $content, 1)
            : rtrim($content) . "\n\n" . $markup;
        if ($updated === $content) {
            return;
        }
        $this->saving = true;
        try {
            wp_update_post(wp_slash(['ID' => $postId, 'post_content' => $updated]));
        } finally {
            $this->saving = false;
        }
    }
}
