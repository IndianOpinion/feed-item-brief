<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedItemBrief\Admin\Pages;
use IndianOpinion\FeedItemBrief\Cli\Command;
use IndianOpinion\FeedItemBrief\Integration\GenQueue;
use IndianOpinion\FeedItemBrief\Integration\SpendMeter;
use IndianOpinion\FeedItemBrief\Model\ModelClient;

/**
 * Wires the services together and registers every hook.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CRON_RUN = 'feed_item_brief_run';
    public const CRON_CLEANUP = 'feed_item_brief_cleanup';
    public const SCHEDULE = 'feed_item_brief_interval';
    public const FEED_SCHEDULE = 'feed_item_brief_feed_interval';

    private static ?self $instance = null;
    private static string $file = '';

    public readonly Clock $clock;
    public readonly Settings $settings;
    public readonly Repository $repository;
    public readonly Registry $registry;
    public readonly Log $log;
    public readonly Gate $gate;
    public readonly DateResolver $resolver;
    public readonly Classifier $classifier;
    public readonly Summariser $summariser;
    public readonly AttributionRenderer $renderer;
    public readonly Drafter $drafter;
    public readonly PageFetcher $fetcher;
    public readonly Intake $intake;
    public readonly Processor $processor;
    public readonly GenQueue $genQueue;
    public readonly Frontend $frontend;
    public readonly ReviewPanel $panel;
    public readonly CoreFeedAdapter $coreFeed;

    /**
     * @param array{clock?: Clock, repository?: Repository, model?: \Closure(): ?ModelClient, fetcher?: PageFetcher, spend_meter?: SpendMeter, gen_queue?: GenQueue} $deps
     */
    public function __construct(array $deps = [])
    {
        $this->clock = $deps['clock'] ?? new SystemClock();
        $this->settings = new Settings();
        if (isset($deps['repository'])) {
            $this->repository = $deps['repository'];
        } else {
            global $wpdb;
            $this->repository = new WpdbRepository($wpdb);
        }
        $this->registry = new Registry();
        $this->log = new Log($this->settings);
        $this->gate = new Gate($this->settings, $this->repository, $this->clock, $deps['spend_meter'] ?? new SpendMeter());
        $tolerance = (int) apply_filters('feed_item_brief_future_tolerance', 600);
        $this->resolver = new DateResolver(max(0, $tolerance));
        $this->classifier = new Classifier($this->settings);
        $this->summariser = new Summariser(
            $this->settings,
            $this->gate,
            new OutputChecker(),
            $this->clock,
            $deps['model'] ?? Summariser::defaultFactory($this->settings)
        );
        $this->renderer = new AttributionRenderer($this->settings);
        $this->drafter = new Drafter($this->settings, $this->renderer);
        $this->fetcher = $deps['fetcher'] ?? new PageFetcher();
        $this->intake = new Intake($this->repository, $this->registry, $this->settings, $this->clock, $this->resolver, $this->log);
        $this->processor = new Processor(
            $this->repository,
            $this->settings,
            $this->clock,
            $this->classifier,
            $this->resolver,
            $this->summariser,
            $this->drafter,
            $this->fetcher,
            $this->log
        );
        $this->genQueue = $deps['gen_queue'] ?? new GenQueue($this->settings);
        $this->frontend = new Frontend($this->renderer, $this->repository);
        $this->panel = new ReviewPanel($this->renderer, $this->clock);
        $this->coreFeed = new CoreFeedAdapter();
    }

    public static function boot(string $file): void
    {
        self::$file = $file;
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        add_action('plugins_loaded', static function (): void {
            self::instance()->init();
        }, 20);
    }

    public static function file(): string
    {
        return self::$file !== '' ? self::$file : dirname(__DIR__) . '/feed-item-brief.php';
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** For tests and for sites that build the plugin with their own services. */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function activate(): void
    {
        global $wpdb;
        Installer::install($wpdb);
        add_option(Settings::SWITCH_OPTION, ['enabled' => false, 'cap' => Settings::DEFAULT_CAP], '', true);
        $settings = get_option(Settings::OPTION, null);
        if (!is_array($settings)) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
            $author = is_array($admins) && isset($admins[0]) ? (int) $admins[0] : 0;
            add_option(Settings::OPTION, ['author' => $author], '', false);
        }
        self::instance()->schedule();
    }

    public static function deactivate(): void
    {
        foreach ([self::CRON_RUN, self::CRON_CLEANUP, CoreFeedAdapter::CRON] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function init(): void
    {
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            Installer::maybeInstall($wpdb);
        }
        if (get_option(Settings::SWITCH_OPTION, null) === null) {
            add_option(Settings::SWITCH_OPTION, ['enabled' => false, 'cap' => Settings::DEFAULT_CAP], '', true);
        }

        $this->registry->add($this->coreFeed);
        do_action('feed_item_brief_register_adapters', $this->registry);
        foreach ($this->registry->all() as $adapter) {
            try {
                $adapter->boot();
            } catch (\Throwable $e) {
                $this->log->error('adapter ' . $adapter->id() . ' failed to boot: ' . $e->getMessage());
            }
        }

        add_action('feed_item_brief_submit', function (mixed $item, mixed $adapterId = ''): void {
            if (is_array($item)) {
                $this->submit($item, is_string($adapterId) ? $adapterId : '');
            }
        }, 10, 2);
        add_action('feed_item_brief_submit_batch', function (mixed $items, mixed $adapterId = '', mixed $sourceKey = ''): void {
            if (is_array($items)) {
                $this->submitBatch($items, is_string($adapterId) ? $adapterId : '', is_string($sourceKey) ? $sourceKey : '');
            }
        }, 10, 3);

        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::CRON_RUN, [$this, 'cronRun']);
        add_action(self::CRON_CLEANUP, [$this, 'cleanup']);
        add_action('init', [$this, 'schedule']);
        $this->genQueue->registerHandler([$this, 'handleJob']);

        $this->frontend->hooks();
        $this->panel->hooks();
        (new Notices($this->settings))->hooks();
        add_action('admin_init', [$this, 'privacy']);
        if (is_admin()) {
            (new Pages($this))->hooks();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('feed-item-brief', new Command($this));
        }
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public function schedules(array $schedules): array
    {
        $minutes = max(1, (int) apply_filters('feed_item_brief_cron_interval', max(1, $this->settings->int('cron_minutes'))));
        $schedules[self::SCHEDULE] = ['interval' => $minutes * 60, 'display' => 'Feed Item Brief queue'];
        $feedMinutes = max(5, (int) apply_filters('feed_item_brief_feed_interval', 30));
        $schedules[self::FEED_SCHEDULE] = ['interval' => $feedMinutes * 60, 'display' => 'Feed Item Brief feeds'];

        return $schedules;
    }

    /**
     * Own runner only when gen-queue is not in use (case 55).
     */
    public function schedule(): void
    {
        if ($this->genQueue->active()) {
            wp_clear_scheduled_hook(self::CRON_RUN);
        } elseif (!wp_next_scheduled(self::CRON_RUN)) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::CRON_RUN);
        }
        if (!wp_next_scheduled(self::CRON_CLEANUP)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_CLEANUP);
        }
        if (CoreFeedAdapter::feeds() !== []) {
            if (!wp_next_scheduled(CoreFeedAdapter::CRON)) {
                wp_schedule_event(time() + 120, self::FEED_SCHEDULE, CoreFeedAdapter::CRON);
            }
        } else {
            wp_clear_scheduled_hook(CoreFeedAdapter::CRON);
        }
    }

    public function cronRun(): void
    {
        $this->processor->run();
    }

    /**
     * gen-queue job handler: the same pipeline for one record.
     */
    public function handleJob(mixed $payload): mixed
    {
        $id = is_array($payload) && is_numeric($payload['record_id'] ?? null) ? (int) $payload['record_id'] : 0;

        return $id > 0 ? $this->processor->processNow($id) : null;
    }

    /**
     * @param array<mixed> $item
     */
    public function submit(array $item, string $adapterId): Submission
    {
        $submission = $this->intake->submit($item, $adapterId);
        $this->afterSubmit($submission);

        return $submission;
    }

    /**
     * @param array<mixed> $items
     * @return list<Submission>
     */
    public function submitBatch(array $items, string $adapterId, string $sourceKey): array
    {
        $out = $this->intake->submitBatch($items, $adapterId, $sourceKey);
        foreach ($out as $submission) {
            $this->afterSubmit($submission);
        }

        return $out;
    }

    private function afterSubmit(Submission $submission): void
    {
        if (!$submission->accepted() || $submission->status() !== Status::QUEUED || !$this->genQueue->active()) {
            return;
        }
        $id = (int) $submission->recordId();
        $record = $this->repository->find($id);
        if ($record === null) {
            return;
        }
        if (!$this->genQueue->enqueue($id, $record->keyHash)) {
            wp_schedule_single_event(time() + 30, self::CRON_RUN);
        }
    }

    /**
     * Manual and WP-CLI path (case 3, 4): submit and process at once.
     *
     * @param array<mixed> $item
     * @return array{submission: Submission, status: string|null}
     */
    public function make(array $item, bool $ignoreCap = false): array
    {
        $submission = $this->intake->submit($item, Registry::MANUAL);
        $status = null;
        if ($submission->accepted() && $submission->status() === Status::QUEUED && $submission->recordId() !== null) {
            $status = $this->processor->processNow($submission->recordId(), $ignoreCap);
        }

        return ['submission' => $submission, 'status' => $status ?? $submission->status()];
    }

    /**
     * Retention (section 6).
     */
    public function cleanup(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $bodyDays = max(1, (int) apply_filters('feed_item_brief_body_retention_days', 7));
        $rowDays = max(1, (int) apply_filters('feed_item_brief_row_retention_days', 90));
        try {
            $this->repository->purgeBodies($now - $bodyDays * DAY_IN_SECONDS);
            $this->repository->purgeRows($now - $rowDays * DAY_IN_SECONDS);
            $this->repository->purgeDays($this->gate->day($now - 400 * DAY_IN_SECONDS));
        } catch (\Throwable $e) {
            $this->log->error('cleanup failed: ' . $e->getMessage());
        }
    }

    public function privacy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            'Feed Item Brief',
            wp_kses_post('<p>' . __('This site uses software to draft summaries of articles from other publishers. The text, title, publisher name and date of those articles are sent to the language model provider the site has configured. No visitor or user data is sent. Author names shown in attributions are the public bylines of the original articles.', 'feed-item-brief') . '</p>')
        );
    }

    /**
     * True when a summariser can run: the filter is hooked, or the library
     * is installed and a model and key are set.
     */
    public function canSummarise(): bool
    {
        if (has_filter('feed_item_brief_summarise')) {
            return true;
        }

        return Model\LlmJsonClient::available() && $this->settings->model() !== '' && $this->settings->apiKey() !== '';
    }
}
