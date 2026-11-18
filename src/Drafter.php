<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\StorageException;

/**
 * Writes the draft post (case 36, 44, 45). Never publishes.
 */
final class Drafter
{
    private static bool $warned = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly AttributionRenderer $renderer,
    ) {
    }

    /**
     * @throws StorageException when the insert fails
     */
    public function draft(Record $record, Item $item, ResolvedDate $date, BodyClass $class, ?Summary $summary): int
    {
        $attribution = new Attribution(
            $item->url(),
            $item->sourceName(),
            $item->bylines(),
            $date->time(),
            $class->excerptNote,
        );

        $blocks = [];
        if ($summary !== null) {
            foreach ($summary->paragraphs as $paragraph) {
                $blocks[] = self::paragraph($paragraph);
            }
            $excerpt = Text::cutAtSentence($summary->paragraphs[0] ?? '', 299);
            $title = $this->settings->string('title_source') === 'original' || trim($summary->headline) === ''
                ? $item->title()
                : $summary->headline;
        } else {
            $teaser = $class->text;
            if ($teaser !== '') {
                $blocks[] = self::paragraph($teaser);
            }
            $blocks[] = self::paragraph('');
            $excerpt = Text::cutAtSentence($teaser, 299);
            $title = $item->title();
        }
        $blocks[] = $this->renderer->blockMarkup($attribution);

        $status = $this->settings->postStatus();
        $author = isset($record->meta['author']) && is_numeric($record->meta['author']) && (int) $record->meta['author'] > 0
            ? (int) $record->meta['author']
            : $this->settings->int('author');

        $flags = $summary !== null ? $summary->flags : [];
        $args = [
            'post_type' => $this->settings->string('post_type') !== '' ? $this->settings->string('post_type') : 'post',
            'post_status' => $status,
            'post_author' => max(0, $author),
            'post_title' => sanitize_text_field($title),
            'post_content' => wp_kses_post(implode("\n\n", $blocks)),
            'post_excerpt' => sanitize_text_field($excerpt),
            'meta_input' => [
                Meta::RECORD => $record->id,
                Meta::SOURCE_URL => $item->url(),
                Meta::SOURCE_NAME => $item->sourceName(),
                Meta::BYLINE => (string) wp_json_encode($item->bylines()),
                Meta::RESOLVED => $date->time(),
                Meta::CLAIMED => $date->claimed() !== null ? (string) $date->claimed() : '',
                Meta::DATE_REASON => $date->stored(),
                Meta::BODY_CLASS => $class->kind,
                Meta::MODE => $class->isLinkOnly() ? 'link_only' : 'summarise',
                Meta::MODEL => $summary !== null ? $summary->model : '',
                Meta::CALL_TIME => $summary !== null ? $summary->calledAt : 0,
                Meta::FLAGS => (string) wp_json_encode($flags),
                Meta::UPDATES => (string) wp_json_encode(self::updates($record)),
                Meta::CORRECTIONS => '[]',
                Meta::EXCERPT_NOTE => $class->excerptNote ? 1 : 0,
            ],
        ];

        $filtered = apply_filters('feed_item_brief_post_args', $args, $record);
        if (is_array($filtered)) {
            $args = array_merge($args, $filtered);
        }
        if (!in_array($args['post_status'] ?? '', ['draft', 'pending'], true)) {
            if (!self::$warned) {
                self::$warned = true;
                _doing_it_wrong(
                    'feed_item_brief_post_args',
                    esc_html__('Feed Item Brief only writes drafts. The post status was set back to draft or pending.', 'feed-item-brief'),
                    '0.1.0'
                );
            }
            $args['post_status'] = $status;
        }
        unset($args['ID'], $args['import_id'], $args['post_date'], $args['post_date_gmt']);
        $args['meta_input'] = array_merge(is_array($args['meta_input'] ?? null) ? $args['meta_input'] : [], [
            Meta::RECORD => $record->id,
            Meta::SOURCE_URL => $item->url(),
            Meta::SOURCE_NAME => $item->sourceName(),
        ]);

        $postId = wp_insert_post(wp_slash($args), true);
        if ($postId instanceof \WP_Error) {
            throw new StorageException('Draft insert failed: ' . $postId->get_error_message());
        }
        if (!is_int($postId) || $postId <= 0) {
            throw new StorageException('Draft insert failed.');
        }

        return $postId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function updates(Record $record): array
    {
        $updates = $record->meta['updates'] ?? [];

        return is_array($updates) ? array_values(array_filter($updates, 'is_array')) : [];
    }

    private static function paragraph(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>" . esc_html(trim(wp_strip_all_tags($text))) . "</p>\n<!-- /wp:paragraph -->";
    }

    /** For tests. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
