<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedItemBrief\Model\LlmJsonClient;

/**
 * Removes everything the plugin created except posts and their meta.
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION, Settings::SWITCH_OPTION, Settings::PAUSE_OPTION, Settings::STATE_OPTION,
        Installer::VERSION_OPTION, Installer::ERROR_OPTION, CoreFeedAdapter::OPTION, CoreFeedAdapter::CURSOR_OPTION,
    ];

    public const HOOKS = [Plugin::CRON_RUN, Plugin::CRON_CLEANUP, CoreFeedAdapter::CRON];

    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        Installer::drop($wpdb);
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        $prefix = LlmJsonClient::STORE_PREFIX;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $prefix) . '%',
            $wpdb->esc_like('_transient_timeout_' . $prefix) . '%',
            $wpdb->esc_like('_transient_feed_item_brief_') . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_timeout_feed_item_brief_') . '%'
        ));
    }
}
