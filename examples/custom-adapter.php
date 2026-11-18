<?php

/**
 * Plugin Name: Feed Item Brief adapter example
 * Description: Shows how an importer hands items to Feed Item Brief.
 *
 * Copy into wp-content/plugins/ and adapt the boot() method to your importer.
 */

declare(strict_types=1);

namespace Example\BriefAdapter;

use IndianOpinion\FeedItemBrief\Adapter;
use IndianOpinion\FeedItemBrief\Registry;

if (!defined('ABSPATH')) {
    exit;
}

final class MyImporterAdapter implements Adapter
{
    public function id(): string
    {
        return 'my-importer';
    }

    public function label(): string
    {
        return 'My importer';
    }

    public function boot(): void
    {
        // Hook into your importer's own "item fetched" event. This example
        // listens to a made-up action that passes one item and its feed.
        add_action('my_importer_item_fetched', function (array $entry, array $feed): void {
            do_action('feed_item_brief_submit', [
                'url' => $entry['link'] ?? '',
                'title' => $entry['title'] ?? '',
                'body' => $entry['content'] ?? '',
                'byline' => $entry['author'] ?? null,
                'published' => $entry['date'] ?? null,
                'guid' => $entry['id'] ?? '',
                'feed_url' => $feed['url'] ?? '',
                'source_key' => (string) ($feed['id'] ?? ''),
                'source_name' => $feed['publisher'] ?? '',
            ], $this->id());
        }, 10, 2);
    }

    public function defaultAuthor(string $sourceKey): ?int
    {
        return null;
    }

    public function timezone(string $sourceKey): ?string
    {
        return $sourceKey === 'mumbai-desk' ? 'Asia/Kolkata' : null;
    }
}

add_action('feed_item_brief_register_adapters', static function (Registry $registry): void {
    $registry->add(new MyImporterAdapter());
});
