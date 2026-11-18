<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Support;

use IndianOpinion\FeedItemBrief\AttributionRenderer;
use IndianOpinion\FeedItemBrief\Drafter;
use IndianOpinion\FeedItemBrief\Integration\SpendMeter;
use IndianOpinion\FeedItemBrief\Intake;
use IndianOpinion\FeedItemBrief\Plugin;
use IndianOpinion\FeedItemBrief\Record;
use IndianOpinion\FeedItemBrief\Settings;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FrozenClock $clock;
    protected MemoryRepository $repo;
    protected FakeModel $model;
    protected bool $libraryInstalled = true;
    protected ?SpendMeter $spendMeter = null;
    protected Plugin $plugin;

    protected function setUp(): void
    {
        \WpStub::reset();
        AttributionRenderer::resetWarning();
        Drafter::resetWarning();
        Intake::resetWarning();
        $this->clock = new FrozenClock();
        $this->repo = new MemoryRepository();
        $this->model = new FakeModel();
        \WpStub::$options[Settings::SWITCH_OPTION] = ['enabled' => true, 'cap' => 25];
        \WpStub::$options[Settings::OPTION] = ['author' => 7, 'use_gen_queue' => false];
        $this->plugin = $this->build();
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
    }

    protected function build(): Plugin
    {
        $model = $this->model;
        $plugin = new Plugin([
            'clock' => $this->clock,
            'repository' => $this->repo,
            'model' => fn () => $this->libraryInstalled ? $model : null,
            'spend_meter' => $this->spendMeter ?? new SpendMeter(),
        ]);
        $plugin->registry->add($plugin->coreFeed);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;

        return $plugin;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function settings(array $settings): void
    {
        $current = \WpStub::$options[Settings::OPTION] ?? [];
        \WpStub::$options[Settings::OPTION] = array_merge(is_array($current) ? $current : [], $settings);
    }

    /**
     * A full article body of $words words, in sentences.
     */
    protected static function body(int $words = 160, string $extra = ''): string
    {
        $sentence = 'The council met on Tuesday and approved the plan for the river bridge after a long debate';
        $parts = explode(' ', $sentence);
        $out = [];
        $count = 0;
        $paragraph = [];
        while ($count < $words) {
            $chunk = array_slice($parts, 0, min(count($parts), $words - $count));
            $paragraph[] = implode(' ', $chunk) . '.';
            $count += count($chunk);
            if (count($paragraph) === 3) {
                $out[] = '<p>' . implode(' ', $paragraph) . '</p>';
                $paragraph = [];
            }
        }
        if ($paragraph !== []) {
            $out[] = '<p>' . implode(' ', $paragraph) . '</p>';
        }

        return implode("\n", $out) . ($extra !== '' ? '<p>' . $extra . '</p>' : '');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function item(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://news.example.org/2026/11/14/river-bridge',
            'title' => 'Council approves river bridge plan',
            'body' => self::body(),
            'source_name' => 'Example News',
            'byline' => 'By Jane Roe',
            'published' => '2026-11-14T09:00:00Z',
            'guid' => 'https://news.example.org/?p=123',
        ], $overrides);
    }

    protected function record(int $id): Record
    {
        $record = $this->repo->find($id);
        self::assertNotNull($record, 'record ' . $id . ' exists');

        return $record;
    }

    /**
     * A summary that the checks accept for the default body.
     *
     * @return array<string, mixed>
     */
    protected static function goodAnswer(): array
    {
        return FakeModel::answer([
            'The council met on Tuesday and approved the plan for the river bridge after a long debate.',
        ]);
    }

    /**
     * @param array<string, mixed> $headers
     * @return callable(string, array<string, mixed>): array<string, mixed>
     */
    protected static function http(int $code, string $body, array $headers = ['content-type' => 'text/html; charset=utf-8']): callable
    {
        return static fn (string $url, array $args): array => [
            'response' => ['code' => $code],
            'headers' => $headers,
            'body' => $body,
        ];
    }
}
