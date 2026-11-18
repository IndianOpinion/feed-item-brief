<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Cli;

use IndianOpinion\FeedItemBrief\Item;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Status;

/**
 * Turn feed items into attributed summary drafts.
 */
final class Command
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Show the switch, the cap, calls today and queue counts.
     *
     * ## EXAMPLES
     *
     *     wp feed-item-brief status
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $s = $this->plugin->settings;
        \WP_CLI::line('Enabled: ' . ($s->isEnabled() ? 'yes' : 'no'));
        \WP_CLI::line(sprintf('Cap: %d%s', $s->cap(), $s->capFromConstant() ? ' (constant)' : ''));
        \WP_CLI::line('Calls today: ' . $this->plugin->gate->callsToday());
        $state = Settings::state();
        \WP_CLI::line('Last run: ' . (is_int($state['last_run'] ?? null) ? gmdate('Y-m-d H:i', $state['last_run']) . ' UTC' : 'never'));
        foreach ($this->plugin->repository->countByStatus() as $status => $count) {
            \WP_CLI::line(sprintf('  %-16s %d', $status, $count));
        }
    }

    /**
     * Process the queue now.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Most items to process. Default: the batch size setting.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : null;
        $result = $this->plugin->processor->run($limit, 300.0);
        \WP_CLI::success(sprintf('Processed %d item(s); stopped: %s.', $result['processed'], $result['stopped']));
    }

    /**
     * Make a draft from one URL now. Obeys the switch and the cap.
     *
     * ## OPTIONS
     *
     * <url>
     * : The article URL.
     *
     * [--title=<title>]
     * : The title. Required unless page fetching is on.
     *
     * [--body-file=<file>]
     * : A file holding the article body (HTML or plain text).
     *
     * [--source=<name>]
     * : The publisher name.
     *
     * [--byline=<names>]
     * : Author names.
     *
     * [--published=<time>]
     * : The publish time (ISO 8601, RFC 2822 or Unix).
     *
     * [--ignore-cap]
     * : Make the call even when the cap is reached. Refused when the cap is set by constant. Logged.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function make(array $args, array $assoc): void
    {
        $ignoreCap = !empty($assoc['ignore-cap']);
        if ($ignoreCap && $this->plugin->settings->capFromConstant()) {
            \WP_CLI::error('--ignore-cap is refused: the cap is set by FEED_ITEM_BRIEF_DAILY_CAP.');
        }
        $item = ['url' => (string) ($args[0] ?? '')];
        foreach (['title' => 'title', 'source' => 'source_name', 'byline' => 'byline', 'published' => 'published'] as $flag => $key) {
            if (isset($assoc[$flag]) && is_string($assoc[$flag])) {
                $item[$key] = $assoc[$flag];
            }
        }
        if (isset($assoc['body-file']) && is_string($assoc['body-file'])) {
            $file = $assoc['body-file'];
            if (!is_readable($file) || !is_file($file)) {
                \WP_CLI::error('Cannot read the body file.');
            }
            $item['body'] = (string) file_get_contents($file);
        }
        if ($ignoreCap) {
            $user = wp_get_current_user();
            error_log(sprintf('[feed-item-brief] --ignore-cap used by user %d (%s) for %s', $user->ID, $user->user_login, $item['url']));
        }
        $result = $this->plugin->make($item, $ignoreCap);
        $submission = $result['submission'];
        $record = $submission->recordId() !== null ? $this->plugin->repository->find($submission->recordId()) : null;
        $line = sprintf('Record %s: %s%s', $submission->recordId() ?? '-', $result['status'] ?? $submission->status(), $submission->reason() !== null ? ' (' . $submission->reason() . ')' : '');
        if ($record !== null && $record->postId !== null) {
            \WP_CLI::success($line . '. Draft ' . $record->postId . '.');

            return;
        }
        \WP_CLI::line($line);
    }

    /**
     * List records.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Only this status.
     *
     * [--since=<date>]
     * : Only records created on or after this date (YYYY-MM-DD).
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $filters = ['limit' => 200];
        if (isset($assoc['status']) && in_array($assoc['status'], Status::all(), true)) {
            $filters['status'] = $assoc['status'];
        }
        if (isset($assoc['since'])) {
            $since = strtotime($assoc['since'] . ' 00:00:00 UTC');
            if ($since === false) {
                \WP_CLI::error('Cannot read --since.');
            }
            $filters['since'] = $since;
        }
        $rows = [];
        foreach ($this->plugin->repository->search($filters) as $r) {
            $rows[] = [
                'id' => $r->id,
                'created' => gmdate('Y-m-d H:i', $r->createdAt),
                'status' => $r->status,
                'reason' => $r->reason,
                'adapter' => $r->adapter,
                'post' => $r->postId ?? '',
                'url' => $r->url,
            ];
        }
        $format = ($assoc['format'] ?? 'table') === 'json' ? 'json' : 'table';
        \WP_CLI\Utils\format_items($format, $rows, ['id', 'created', 'status', 'reason', 'adapter', 'post', 'url']);
    }

    /**
     * Put a record back in the queue.
     *
     * ## OPTIONS
     *
     * <record-id>
     * : The record id.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function retry(array $args, array $assoc): void
    {
        $id = (int) ($args[0] ?? 0);
        if (!$this->plugin->retry($id)) {
            \WP_CLI::error('That record does not exist or cannot be retried.');
        }
        \WP_CLI::success('Record ' . $id . ' is queued.');
    }

    /**
     * Fetch, classify and date one URL without a model call and without
     * writing anything.
     *
     * ## OPTIONS
     *
     * <url>
     * : The article URL.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $url = (string) ($args[0] ?? '');
        $fetched = $this->plugin->fetcher->fetch($url);
        $page = $fetched['page'];
        try {
            $item = Item::fromArray([
                'url' => $url,
                'title' => $page !== null && $page->title !== '' ? $page->title : $url,
                'body' => $page !== null ? $page->articleBody : '',
                'language' => $page !== null ? $page->language : '',
            ]);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        $class = $this->plugin->classifier->classify($item, $page);
        $date = $this->plugin->resolver->resolve($item, $page, [], null, $this->plugin->clock->now());
        \WP_CLI::line('Page: ' . $fetched['reason']);
        \WP_CLI::line('Class: ' . $class->kind . ' (' . $class->action . ($class->reason !== '' ? ', ' . $class->reason : '') . ')');
        \WP_CLI::line('Resolved time: ' . gmdate('Y-m-d H:i:s', $date->time()) . ' UTC');
        \WP_CLI::line('Date reason: ' . $date->stored());
    }
}
