<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Unit;

use IndianOpinion\FeedItemBrief\Cli\Command;
use IndianOpinion\FeedItemBrief\Settings;
use IndianOpinion\FeedItemBrief\Status;
use IndianOpinion\FeedItemBrief\Tests\Support\TestCase;

require_once dirname(__DIR__) . '/stubs/wp-cli.php';

final class CliTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::$out = [];
    }

    /** Test 3 (case 4). */
    public function testMakeRunsThePipelineAtOnce(): void
    {
        $this->model->queue(self::goodAnswer());
        $file = tempnam(sys_get_temp_dir(), 'fib');
        file_put_contents($file, self::body());
        (new Command($this->plugin))->make(['https://news.example.org/2026/11/14/cli'], ['title' => 'Council approves river bridge plan', 'body-file' => $file, 'source' => 'Example News', 'published' => '2026-11-14T09:00:00Z']);
        unlink($file);

        self::assertSame(Status::DRAFTED, $this->record(1)->status);
        self::assertSame(1, $this->plugin->gate->callsToday());
        self::assertStringStartsWith('Success:', \WP_CLI::$out[0]);
    }

    /** Test 55 (case 60). Runs alone: it defines the cap constant. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testIgnoreCapIsRefusedWhenTheCapConstantIsSet(): void
    {
        \WpStub::$options[Settings::SWITCH_OPTION]['cap'] = 0;
        $this->model->queue(self::goodAnswer());
        $file = (string) tempnam(sys_get_temp_dir(), 'fib');
        file_put_contents($file, self::body());
        (new Command($this->plugin))->make(['https://news.example.org/2026/11/14/a'], ['title' => 'Council approves river bridge plan', 'body-file' => $file, 'ignore-cap' => true]);
        unlink($file);
        self::assertSame(1, $this->plugin->gate->callsToday(), '--ignore-cap works while the cap is a setting');

        define('FEED_ITEM_BRIEF_DAILY_CAP', 0);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--ignore-cap is refused');
        (new Command($this->plugin))->make(['https://news.example.org/2026/11/14/b'], ['title' => 'Another title here', 'ignore-cap' => true]);
    }

    public function testStatusLogRetryAndCheck(): void
    {
        $command = new Command($this->plugin);
        $this->plugin->intake->submit($this->item(['body' => '']), 'core-feed');
        $this->plugin->processor->run();
        $command->status([], []);
        self::assertContains('Enabled: yes', \WP_CLI::$out);

        \WP_CLI::$out = [];
        $command->log([], ['format' => 'json', 'status' => 'no_body']);
        self::assertStringContainsString('"status":"no_body"', \WP_CLI::$out[0]);

        try {
            $command->retry(['1'], []);
            self::fail('no_body is not retryable');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot be retried', $e->getMessage());
        }

        \WP_CLI::$out = [];
        \WpStub::$http = self::http(200, '<html lang="en"><head><title>Story</title><script type="application/ld+json">' . json_encode(['@type' => 'NewsArticle', 'datePublished' => '2026-11-13T09:00:00Z', 'articleBody' => trim(strip_tags(self::body(130)))]) . '</script></head></html>');
        $command->check(['https://news.example.org/story'], []);
        self::assertContains('Class: full (summarise, full)', \WP_CLI::$out, implode(' / ', \WP_CLI::$out));
        self::assertContains('Date reason: date_missing,date_page', \WP_CLI::$out);
        self::assertSame([], $this->model->calls);
        self::assertCount(1, $this->repo->rows, 'check writes nothing');
    }
}
