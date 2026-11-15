<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Admin;

use IndianOpinion\FeedItemBrief\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedItemBrief\Installer;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Url;

/**
 * Settings, log and "Brief from URL" screens (section 5.6). Every form
 * carries a nonce and checks a capability.
 */
final class Pages
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menus']);
        add_action('admin_post_feed_item_brief_save', [$this, 'saveSettings']);
        add_action('admin_post_feed_item_brief_make', [$this, 'handleMake']);
        add_action('admin_post_feed_item_brief_retry', [$this, 'handleRetry']);
    }

    public function menus(): void
    {
        add_options_page(__('Feed Item Brief', 'feed-item-brief'), __('Feed Item Brief', 'feed-item-brief'), Settings::capability(), 'feed-item-brief', [$this, 'renderSettings']);
        add_management_page(__('Feed Item Brief log', 'feed-item-brief'), __('Feed Item Brief log', 'feed-item-brief'), Settings::capability(), 'feed-item-brief-log', [$this, 'renderLog']);
        add_posts_page(__('Brief from URL', 'feed-item-brief'), __('Brief from URL', 'feed-item-brief'), Settings::makeCapability(), 'feed-item-brief-make', [$this, 'renderMake']);
    }

    /**
     * Clean the posted settings. Unknown keys are dropped.
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitizeSettings(array $input): array
    {
        $text = static fn (string $k): string => is_string($input[$k] ?? null) ? sanitize_text_field($input[$k]) : '';
        $int = static fn (string $k, int $min, int $max, int $default): int => is_numeric($input[$k] ?? null) ? max($min, min($max, (int) $input[$k])) : $default;
        $choice = static fn (string $k, array $allowed, string $default): string => in_array($input[$k] ?? null, $allowed, true) ? (string) $input[$k] : $default;
        $lines = static function (string $k) use ($input): array {
            $raw = is_string($input[$k] ?? null) ? $input[$k] : '';
            $out = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $line = sanitize_text_field($line);
                if ($line !== '') {
                    $out[] = $line;
                }
            }

            return $out;
        };

        $out = [
            'provider' => $choice('provider', ['openrouter', 'openai', 'generic'], 'openrouter'),
            'base_url' => '',
            'model' => $text('model'),
            'post_status' => $choice('post_status', ['draft', 'pending'], 'draft'),
            'author' => $int('author', 0, PHP_INT_MAX, 0),
            'title_source' => $choice('title_source', ['headline', 'original'], 'headline'),
            'paywall_mode' => $choice('paywall_mode', ['teaser', 'link_only', 'skip'], 'link_only'),
            'no_body_mode' => $choice('no_body_mode', ['skip', 'link_only'], 'skip'),
            'fetch_pages' => !empty($input['fetch_pages']),
            'max_age_hours' => $int('max_age_hours', 0, 8760, 72),
            'timeout' => $int('timeout', 5, 300, 45),
            'disclosure_on' => !empty($input['disclosure_on']),
            'disclosure_text' => $text('disclosure_text'),
            'use_gen_queue' => !empty($input['use_gen_queue']),
            'sponsored_phrases' => $lines('sponsored_phrases'),
            'paywall_phrases' => $lines('paywall_phrases'),
            'error_log' => !empty($input['error_log']),
        ];
        $base = is_string($input['base_url'] ?? null) ? Url::clean($input['base_url']) : '';
        if ($base !== '' && Url::isValid($base)) {
            $out['base_url'] = esc_url_raw($base);
        }
        if ($out['disclosure_on'] && $out['disclosure_text'] === '') {
            $out['disclosure_text'] = (string) Settings::defaults()['disclosure_text'];
        }
        if (is_string($input['api_key'] ?? null) && trim($input['api_key']) !== '') {
            $out['api_key'] = trim(sanitize_text_field($input['api_key']));
        }
        if (!empty($input['clear_key'])) {
            $out['api_key'] = '';
        }

        return $out;
    }

    /**
     * Parse the feed list: one feed per line, "URL | source name | author id | time zone | enabled".
     *
     * @return list<array<string, mixed>>
     */
    public static function parseFeeds(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $out[] = [
                'url' => $parts[0],
                'source_name' => $parts[1] ?? '',
                'author' => $parts[2] ?? 0,
                'timezone' => $parts[3] ?? '',
                'enabled' => !isset($parts[4]) || $parts[4] === '' || $parts[4] === '1' || strtolower($parts[4]) === 'yes',
            ];
        }

        return $out;
    }

    public function saveSettings(): void
    {
        if (!current_user_can(Settings::capability())) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'feed-item-brief'), 403);
        }
        check_admin_referer('feed_item_brief_save');
        $input = isset($_POST['feed_item_brief']) && is_array($_POST['feed_item_brief']) ? wp_unslash($_POST['feed_item_brief']) : [];
        $settings = $this->plugin->settings;
        $settings->save(self::sanitizeSettings($input));
        if (!$settings->capFromConstant() && is_numeric($input['cap'] ?? null)) {
            $settings->setCap((int) $input['cap']);
        }
        $old = [];
        foreach (CoreFeedAdapter::feeds() as $feed) {
            $old[$feed['key']] = $feed;
        }
        $feeds = [];
        foreach (self::parseFeeds(is_string($input['feeds'] ?? null) ? $input['feeds'] : '') as $feed) {
            $key = CoreFeedAdapter::keyFor(Url::clean((string) $feed['url']));
            $feeds[] = $feed + ['failures' => $old[$key]['failures'] ?? 0, 'last_error' => $old[$key]['last_error'] ?? ''];
        }
        CoreFeedAdapter::saveFeeds($feeds);

        $message = 'saved';
        $wantOn = !empty($input['enabled']);
        if ($wantOn && !$this->plugin->canSummarise()) {
            $settings->setEnabled(false);
            $message = 'needs_summariser';
        } else {
            $settings->setEnabled($wantOn);
        }
        $this->plugin->schedule();
        wp_safe_redirect(add_query_arg(['page' => 'feed-item-brief', 'fib' => $message], admin_url('options-general.php')));
        exit;
    }

    public function renderSettings(): void
    {
        if (!current_user_can(Settings::capability())) {
            return;
        }
        $s = $this->plugin->settings;
        $all = $s->all();
        $now = time();
        $state = Settings::state();
        $counts = $this->plugin->repository->countByStatus();
        $queue = 0;
        foreach ([Status::QUEUED, Status::PAUSED, Status::CAPPED, Status::SPEND_DENIED, Status::AUTH_FAILED, Status::NO_CREDIT, Status::INSERT_FAILED, Status::PROCESSING] as $st) {
            $queue += $counts[$st] ?? 0;
        }
        $lastRun = is_int($state['last_run'] ?? null) ? $state['last_run'] : 0;
        $capReached = is_array($state['cap_reached'] ?? null) ? $state['cap_reached'] : [];
        $filterSummariser = (bool) has_filter('feed_item_brief_summarise');
        $notice = isset($_GET['fib']) ? sanitize_key(wp_unslash((string) $_GET['fib'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('Feed Item Brief', 'feed-item-brief') . '</h1>';
        if ($notice === 'needs_summariser') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The plugin stays off until a model and API key are set, or a summariser is hooked into feed_item_brief_summarise.', 'feed-item-brief') . '</p></div>';
        } elseif ($notice === 'saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'feed-item-brief') . '</p></div>';
        }
        if (Installer::schemaError() !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html__('The database tables are incomplete. The plugin is switched off.', 'feed-item-brief') . '</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:640px"><tbody>';
        printf('<tr><th>%s</th><td>%d / %d</td></tr>', esc_html__('Model calls today', 'feed-item-brief'), (int) $this->plugin->gate->callsToday(), (int) $s->cap());
        if (($capReached['day'] ?? '') === $this->plugin->gate->day()) {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Cap reached at', 'feed-item-brief'), esc_html((string) wp_date('H:i', (int) ($capReached['time'] ?? 0))));
        }
        $late = $lastRun === 0 || $lastRun < $now - 1800;
        printf(
            '<tr><th>%s</th><td style="%s">%s</td></tr>',
            esc_html__('Last queue run', 'feed-item-brief'),
            $late ? 'color:#b32d2e;font-weight:600' : '',
            esc_html($lastRun > 0 ? (string) wp_date('Y-m-d H:i', $lastRun) : __('never', 'feed-item-brief'))
        );
        printf('<tr><th>%s</th><td>%d</td></tr>', esc_html__('Queue length', 'feed-item-brief'), $queue);
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('feed_item_brief_save');
        echo '<input type="hidden" name="action" value="feed_item_brief_save">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $constantOff = defined('FEED_ITEM_BRIEF_DISABLED') && constant('FEED_ITEM_BRIEF_DISABLED');
        $this->row(__('Enabled', 'feed-item-brief'), '<label><input type="checkbox" name="feed_item_brief[enabled]" value="1"' . checked($s->isEnabled(), true, false) . disabled($constantOff, true, false) . '> '
            . esc_html($constantOff ? __('Switched off by FEED_ITEM_BRIEF_DISABLED', 'feed-item-brief') : __('Process items and call the model', 'feed-item-brief')) . '</label>');

        if (!$filterSummariser) {
            $this->row(__('Provider', 'feed-item-brief'), $this->select('provider', ['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'generic' => __('Other chat completions endpoint', 'feed-item-brief')], (string) $all['provider']));
            $this->row(__('Endpoint base URL', 'feed-item-brief'), '<input type="url" class="regular-text" name="feed_item_brief[base_url]" value="' . esc_attr((string) $all['base_url']) . '"><p class="description">' . esc_html__('Only for "Other chat completions endpoint".', 'feed-item-brief') . '</p>');
            $this->row(__('Model', 'feed-item-brief'), $s->modelFromConstant()
                ? '<code>' . esc_html($s->model()) . '</code> ' . esc_html__('(set by constant)', 'feed-item-brief')
                : '<input type="text" class="regular-text" name="feed_item_brief[model]" value="' . esc_attr((string) $all['model']) . '">');
            $keyState = $s->apiKeyFromConstant() ? __('set by constant', 'feed-item-brief') : ($s->apiKey() !== '' ? __('set', 'feed-item-brief') : __('not set', 'feed-item-brief'));
            $this->row(__('API key', 'feed-item-brief'), $s->apiKeyFromConstant()
                ? esc_html($keyState)
                : '<input type="password" class="regular-text" name="feed_item_brief[api_key]" value="" autocomplete="off"> <span>' . esc_html($keyState) . '</span>'
                . ' <label><input type="checkbox" name="feed_item_brief[clear_key]" value="1"> ' . esc_html__('Clear', 'feed-item-brief') . '</label>');
            $this->row(__('Model timeout (seconds)', 'feed-item-brief'), '<input type="number" min="5" max="300" name="feed_item_brief[timeout]" value="' . esc_attr((string) $all['timeout']) . '">');
        } else {
            echo '<input type="hidden" name="feed_item_brief[provider]" value="' . esc_attr((string) $all['provider']) . '">';
            echo '<input type="hidden" name="feed_item_brief[model]" value="' . esc_attr((string) $all['model']) . '">';
            $this->row(__('Summariser', 'feed-item-brief'), esc_html__('Supplied by the site through feed_item_brief_summarise. No provider or key is needed.', 'feed-item-brief'));
        }
        $this->row(__('Daily cap (model calls)', 'feed-item-brief'), $s->capFromConstant()
            ? '<code>' . (int) $s->cap() . '</code> ' . esc_html__('(set by constant)', 'feed-item-brief')
            : '<input type="number" min="0" name="feed_item_brief[cap]" value="' . esc_attr((string) $s->cap()) . '"><p class="description">' . esc_html__('Retries count. Link-only drafts do not. 0 means no calls.', 'feed-item-brief') . '</p>');
        $this->row(__('Post status', 'feed-item-brief'), $this->select('post_status', ['draft' => __('Draft', 'feed-item-brief'), 'pending' => __('Pending review', 'feed-item-brief')], (string) $all['post_status']));
        $this->row(__('Author', 'feed-item-brief'), (string) wp_dropdown_users(['name' => 'feed_item_brief[author]', 'selected' => (int) $all['author'], 'echo' => false, 'capability' => ['edit_posts']]));
        $this->row(__('Title', 'feed-item-brief'), $this->select('title_source', ['headline' => __('Model headline', 'feed-item-brief'), 'original' => __('Original title', 'feed-item-brief')], (string) $all['title_source']));
        $this->row(__('Paywalled items', 'feed-item-brief'), $this->select('paywall_mode', ['link_only' => __('Link-only draft', 'feed-item-brief'), 'teaser' => __('Summarise the public excerpt', 'feed-item-brief'), 'skip' => __('Skip', 'feed-item-brief')], (string) $all['paywall_mode']));
        $this->row(__('Items with no body', 'feed-item-brief'), $this->select('no_body_mode', ['skip' => __('Skip', 'feed-item-brief'), 'link_only' => __('Link-only draft', 'feed-item-brief')], (string) $all['no_body_mode']));
        $this->row(__('Fetch source pages', 'feed-item-brief'), $this->checkbox('fetch_pages', (bool) $all['fetch_pages'], __('Read each article page once for its date, title and paywall signals', 'feed-item-brief')));
        $this->row(__('Maximum age (hours)', 'feed-item-brief'), '<input type="number" min="0" name="feed_item_brief[max_age_hours]" value="' . esc_attr((string) $all['max_age_hours']) . '"><p class="description">' . esc_html__('0 means no limit.', 'feed-item-brief') . '</p>');
        $this->row(__('Disclosure line', 'feed-item-brief'), $this->checkbox('disclosure_on', (bool) $all['disclosure_on'], __('Show', 'feed-item-brief'))
            . '<br><input type="text" class="large-text" name="feed_item_brief[disclosure_text]" value="' . esc_attr((string) $all['disclosure_text']) . '">');
        if (function_exists('gen_queue_enqueue')) {
            $this->row(__('Use gen-queue', 'feed-item-brief'), $this->checkbox('use_gen_queue', (bool) $all['use_gen_queue'], __('Run summaries as gen-queue jobs', 'feed-item-brief')));
        } else {
            echo '<input type="hidden" name="feed_item_brief[use_gen_queue]" value="' . ((bool) $all['use_gen_queue'] ? '1' : '') . '">';
        }
        $this->row(__('Sponsored phrases', 'feed-item-brief'), '<textarea class="large-text" rows="4" name="feed_item_brief[sponsored_phrases]">' . esc_textarea(implode("\n", $s->list('sponsored_phrases'))) . '</textarea>');
        $this->row(__('Paywall phrases', 'feed-item-brief'), '<textarea class="large-text" rows="4" name="feed_item_brief[paywall_phrases]">' . esc_textarea(implode("\n", $s->list('paywall_phrases'))) . '</textarea>');
        $this->row(__('Error log', 'feed-item-brief'), $this->checkbox('error_log', (bool) $all['error_log'], __('Write one line per final status to the PHP error log', 'feed-item-brief')));

        $lines = [];
        foreach (CoreFeedAdapter::feeds() as $feed) {
            $lines[] = implode(' | ', [$feed['url'], $feed['source_name'], (string) $feed['author'], $feed['timezone'], $feed['enabled'] ? '1' : '0']);
        }
        $this->row(__('Feeds (WordPress core)', 'feed-item-brief'), '<textarea class="large-text code" rows="6" name="feed_item_brief[feeds]">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
            . '<p class="description">' . esc_html__('One feed per line: URL | source name | author user id | time zone | enabled (1 or 0). Up to 200 feeds.', 'feed-item-brief') . '</p>');
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        $failing = array_filter(CoreFeedAdapter::feeds(), [CoreFeedAdapter::class, 'isFailing']);
        if ($failing !== []) {
            echo '<h2>' . esc_html__('Failing feeds', 'feed-item-brief') . '</h2><ul>';
            foreach ($failing as $feed) {
                echo '<li><code>' . esc_html($feed['url']) . '</code>: ' . esc_html($feed['last_error']) . ' (' . (int) $feed['failures'] . ')</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public function renderLog(): void
    {
        if (!current_user_can(Settings::capability())) {
            return;
        }
        $get = static fn (string $k): string => isset($_GET[$k]) ? sanitize_text_field(wp_unslash((string) $_GET[$k])) : '';
        $filters = ['limit' => 50, 'offset' => max(0, (int) $get('offset'))];
        foreach (['status', 'reason', 'adapter'] as $key) {
            if ($get($key) !== '') {
                $filters[$key] = $get($key);
            }
        }
        if ($get('since') !== '') {
            $since = strtotime($get('since') . ' 00:00:00');
            if ($since !== false) {
                $filters['since'] = $since;
            }
        }
        $records = $this->plugin->repository->search($filters);

        echo '<div class="wrap"><h1>' . esc_html__('Feed Item Brief log', 'feed-item-brief') . '</h1>';
        if ($get('fib') !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($get('fib') === 'retried' ? __('The record is back in the queue.', 'feed-item-brief') : __('That record cannot be retried.', 'feed-item-brief')) . '</p></div>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="feed-item-brief-log">';
        echo '<select name="status"><option value="">' . esc_html__('Any status', 'feed-item-brief') . '</option>';
        foreach (Status::all() as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($get('status'), $status, false) . '>' . esc_html($status) . '</option>';
        }
        echo '</select> <input type="text" name="reason" placeholder="' . esc_attr__('Reason', 'feed-item-brief') . '" value="' . esc_attr($get('reason')) . '">';
        echo ' <input type="text" name="adapter" placeholder="' . esc_attr__('Adapter', 'feed-item-brief') . '" value="' . esc_attr($get('adapter')) . '">';
        echo ' <input type="date" name="since" value="' . esc_attr($get('since')) . '"> ';
        submit_button(__('Filter', 'feed-item-brief'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('ID', 'feed-item-brief'), __('Created', 'feed-item-brief'), __('Status', 'feed-item-brief'), __('Reason', 'feed-item-brief'), __('Adapter', 'feed-item-brief'), __('Item', 'feed-item-brief'), __('Actions', 'feed-item-brief')] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($records as $r) {
            echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html((string) wp_date('Y-m-d H:i', $r->createdAt)) . '</td>';
            echo '<td>' . esc_html($r->status) . '</td><td>' . esc_html($r->reason . ($r->failures !== [] ? ': ' . implode('; ', $r->failures) : '')) . '</td>';
            echo '<td>' . esc_html($r->adapter) . '</td>';
            echo '<td>' . (Url::isValid($r->url) ? '<a href="' . esc_url($r->url) . '" rel="noopener noreferrer" target="_blank">' . esc_html($r->title !== '' ? $r->title : $r->url) . '</a>' : esc_html($r->title)) . '</td><td>';
            if ($r->postId !== null && current_user_can('edit_post', $r->postId)) {
                echo '<a href="' . esc_url((string) get_edit_post_link($r->postId)) . '">' . esc_html__('View draft', 'feed-item-brief') . '</a> ';
            }
            if (in_array($r->status, Status::RETRYABLE, true)) {
                $confirm = $r->status === Status::STALE ? ' onclick="return confirm(\'' . esc_js(__('This item is older than the maximum age. Summarise it anyway?', 'feed-item-brief')) . '\');"' : '';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                wp_nonce_field('feed_item_brief_retry_' . $r->id);
                echo '<input type="hidden" name="action" value="feed_item_brief_retry"><input type="hidden" name="record" value="' . (int) $r->id . '">';
                echo '<button type="submit" class="button button-small"' . $confirm . '>' . esc_html__('Retry', 'feed-item-brief') . '</button></form>';
            }
            echo '</td></tr>';
        }
        if ($records === []) {
            echo '<tr><td colspan="7">' . esc_html__('No records.', 'feed-item-brief') . '</td></tr>';
        }
        echo '</tbody></table>';
        if (count($records) === 50) {
            echo '<p><a class="button" href="' . esc_url(add_query_arg('offset', ((int) $filters['offset']) + 50)) . '">' . esc_html__('Older', 'feed-item-brief') . '</a></p>';
        }
        echo '</div>';
    }

    public function handleRetry(): void
    {
        $id = isset($_POST['record']) ? absint(wp_unslash((string) $_POST['record'])) : 0;
        if (!current_user_can(Settings::capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'feed-item-brief'), 403);
        }
        check_admin_referer('feed_item_brief_retry_' . $id);
        $ok = $this->plugin->processor->retry($id);
        wp_safe_redirect(add_query_arg(['page' => 'feed-item-brief-log', 'fib' => $ok ? 'retried' : 'not_retried'], admin_url('tools.php')));
        exit;
    }

    public function renderMake(): void
    {
        if (!current_user_can(Settings::makeCapability())) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Brief from URL', 'feed-item-brief') . '</h1>';
        $result = isset($_GET['fib']) ? sanitize_key(wp_unslash((string) $_GET['fib'])) : '';
        if ($result !== '') {
            $post = isset($_GET['post']) ? absint(wp_unslash((string) $_GET['post'])) : 0;
            $text = sprintf(__('Result: %s.', 'feed-item-brief'), $result);
            echo '<div class="notice notice-info"><p>' . esc_html($text);
            if ($post > 0 && current_user_can('edit_post', $post)) {
                echo ' <a href="' . esc_url((string) get_edit_post_link($post)) . '">' . esc_html__('Open the draft', 'feed-item-brief') . '</a>';
            }
            echo '</p></div>';
        }
        echo '<p>' . esc_html__('Paste an article URL. The item is processed at once and counts against the daily cap. The result is a draft.', 'feed-item-brief') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('feed_item_brief_make');
        echo '<input type="hidden" name="action" value="feed_item_brief_make"><table class="form-table" role="presentation"><tbody>';
        $this->row(__('URL', 'feed-item-brief'), '<input type="url" class="large-text" name="fib[url]" required>');
        $this->row(__('Title', 'feed-item-brief'), '<input type="text" class="large-text" name="fib[title]">');
        $this->row(__('Body', 'feed-item-brief'), '<textarea class="large-text" rows="10" name="fib[body]"></textarea>');
        $this->row(__('Source name', 'feed-item-brief'), '<input type="text" class="regular-text" name="fib[source_name]">');
        $this->row(__('Byline', 'feed-item-brief'), '<input type="text" class="regular-text" name="fib[byline]">');
        $this->row(__('Published', 'feed-item-brief'), '<input type="text" class="regular-text" name="fib[published]" placeholder="2026-11-14T09:30:00+00:00">');
        echo '</tbody></table>';
        submit_button(__('Make draft', 'feed-item-brief'));
        echo '</form></div>';
    }

    public function handleMake(): void
    {
        if (!current_user_can(Settings::makeCapability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'feed-item-brief'), 403);
        }
        check_admin_referer('feed_item_brief_make');
        $input = isset($_POST['fib']) && is_array($_POST['fib']) ? wp_unslash($_POST['fib']) : [];
        $item = [];
        foreach (['url', 'title', 'source_name', 'byline', 'published'] as $key) {
            if (is_string($input[$key] ?? null) && trim($input[$key]) !== '') {
                $item[$key] = sanitize_text_field($input[$key]);
            }
        }
        if (is_string($input['body'] ?? null)) {
            $item['body'] = wp_kses_post($input['body']);
        }
        $result = $this->plugin->make($item);
        $record = $result['submission']->recordId() !== null ? $this->plugin->repository->find((int) $result['submission']->recordId()) : null;
        $args = ['page' => 'feed-item-brief-make', 'fib' => $result['status'] ?? 'failed'];
        if ($record !== null && $record->postId !== null) {
            $args['post'] = $record->postId;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
        exit;
    }

    private function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>';
    }

    /**
     * @param array<string, string> $options
     */
    private function select(string $name, array $options, string $current): string
    {
        $html = '<select name="feed_item_brief[' . esc_attr($name) . ']">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    private function checkbox(string $name, bool $on, string $label): string
    {
        return '<label><input type="checkbox" name="feed_item_brief[' . esc_attr($name) . ']" value="1"' . checked($on, true, false) . '> ' . esc_html($label) . '</label>';
    }
}
