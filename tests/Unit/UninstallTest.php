<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Attribution;
use IndianOpinion\FeedItemBrief\Installer;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;
use IndianOpinion\FeedItemBrief\Uninstaller;

final class UninstallTest extends TestCase
{
    /** Test 62 (section 6). */
    public function testUninstallDropsTablesAndOptionsAndKeepsPosts(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $this->model->queue(self::goodAnswer());
        $this->plugin->intake->submit($this->item(), 'core-feed');
        $this->plugin->processor->run();
        $postId = (int) $this->record(1)->postId;
        \WpStub::$options[Installer::VERSION_OPTION] = '1';
        \WpStub::$scheduled[Plugin::CRON_RUN] = [1, 'x'];

        Uninstaller::run();

        $sql = implode("\n", $wpdb->queries);
        self::assertStringContainsString('DROP TABLE IF EXISTS `wp_feed_item_brief_items`', $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS `wp_feed_item_brief_daily`', $sql);
        self::assertStringContainsString('feed\\\\_item\\\\_brief\\\\_llm%', $sql);
        foreach ([Settings::OPTION, Settings::SWITCH_OPTION, Settings::STATE_OPTION, Installer::VERSION_OPTION] as $option) {
            self::assertArrayNotHasKey($option, \WpStub::$options);
        }
        self::assertSame([], \WpStub::$scheduled);

        self::assertArrayHasKey($postId, \WpStub::$posts);
        self::assertNotNull(Attribution::fromPost($postId));
        self::assertStringContainsString('rel="nofollow noopener">Example News</a>', \WpStub::$posts[$postId]->post_content);
        $wpdb = null;
    }

    public function testMissingColumnSwitchesThePluginOff(): void
    {
        global $wpdb;
        $wpdb = new class () extends \wpdb {
            public function get_col(string $sql, int $x = 0): array
            {
                $this->queries[] = $sql;

                return str_contains($sql, 'items') ? array_diff(Installer::ITEM_COLUMNS, ['body_hash']) : Installer::DAILY_COLUMNS;
            }
        };
        Installer::install($wpdb);

        self::assertSame('wp_feed_item_brief_items.body_hash', Installer::schemaError());
        self::assertFalse((new Settings())->isEnabled());
        self::assertArrayNotHasKey(Installer::VERSION_OPTION, \WpStub::$options);
        $wpdb = null;
    }

    public function testCompleteSchemaRecordsTheVersion(): void
    {
        global $wpdb;
        $wpdb = new class () extends \wpdb {
            public function get_col(string $sql, int $x = 0): array
            {
                return str_contains($sql, 'items') ? Installer::ITEM_COLUMNS : Installer::DAILY_COLUMNS;
            }
        };
        Installer::install($wpdb);
        self::assertSame('', Installer::schemaError());
        self::assertSame(Installer::DB_VERSION, \WpStub::$options[Installer::VERSION_OPTION]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS wp_feed_item_brief_items', implode("\n", $wpdb->queries));
        $wpdb = null;
    }
}
