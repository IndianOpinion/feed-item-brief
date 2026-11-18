<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * The attribution block and its guards (case 45 to 49), plus the opt-in
 * canonical link.
 */
final class Frontend
{
    public const NOTICE_TRANSIENT = 'feed_item_brief_blocked_';

    private bool $reverting = false;

    public function __construct(
        private readonly AttributionRenderer $renderer,
        private readonly Repository $repository,
    ) {
    }

    public function hooks(): void
    {
        add_action('init', [$this, 'registerBlock']);
        add_filter('the_content', [$this, 'ensureAttribution'], 20);
        add_action('transition_post_status', [$this, 'guardPublish'], 10, 3);
        add_action('wp_head', [$this, 'canonical'], 1);
    }

    public function registerBlock(): void
    {
        wp_register_script(
            'feed-item-brief-attribution',
            plugins_url('assets/attribution-block.js', Plugin::file()),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-data'],
            Plugin::VERSION,
            true
        );
        register_block_type(AttributionRenderer::BLOCK, [
            'api_version' => '3',
            'title' => __('Source attribution', 'feed-item-brief'),
            'category' => 'text',
            'editor_script' => 'feed-item-brief-attribution',
            'render_callback' => [$this, 'renderBlock'],
            'uses_context' => ['postId'],
            'supports' => ['html' => false, 'inserter' => false, 'multiple' => false, 'reusable' => false],
            'attributes' => ['lock' => ['type' => 'object']],
        ]);
    }

    /**
     * @param array<mixed> $attributes
     */
    public function renderBlock(array $attributes, string $content = '', ?object $block = null): string
    {
        $postId = 0;
        if ($block !== null && isset($block->context) && is_array($block->context) && is_numeric($block->context['postId'] ?? null)) {
            $postId = (int) $block->context['postId'];
        }
        if ($postId === 0) {
            $postId = (int) get_the_ID();
        }
        $attribution = Attribution::fromPost($postId);

        return $attribution !== null ? $this->renderer->render($attribution) : $content;
    }

    /**
     * Case 46: the attribution comes back if an editor removed it.
     */
    public function ensureAttribution(mixed $content): mixed
    {
        if (!is_string($content)) {
            return $content;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return $content;
        }
        $attribution = Attribution::fromPost((int) $post->ID);
        if ($attribution === null || AttributionRenderer::containsBlock($content, $attribution)) {
            return $content;
        }

        return $content . "\n" . $this->renderer->render($attribution);
    }

    /**
     * Case 49: a brief cannot be published without valid attribution.
     */
    public function guardPublish(string $new, string $old, mixed $post): void
    {
        if ($this->reverting || !$post instanceof \WP_Post || !in_array($new, ['publish', 'future'], true)) {
            return;
        }
        $id = (int) $post->ID;
        $ours = get_post_meta($id, Meta::RECORD, true) !== '' && get_post_meta($id, Meta::RECORD, true) !== false;
        if (!$ours) {
            try {
                $ours = $this->repository->findByPost($id) !== null;
            } catch (\Throwable) {
                $ours = false;
            }
        }
        if (!$ours || Attribution::fromPost($id) !== null) {
            return;
        }
        $this->reverting = true;
        try {
            wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        } finally {
            $this->reverting = false;
        }
        $user = get_current_user_id();
        set_transient(self::NOTICE_TRANSIENT . $user, $id, 300);
    }

    /**
     * Opt-in canonical link to the source (ruling on open question 3).
     */
    public function canonical(): void
    {
        if (!is_singular()) {
            return;
        }
        $id = (int) get_queried_object_id();
        $attribution = Attribution::fromPost($id);
        if ($attribution === null || apply_filters('feed_item_brief_canonical', false, $id, $attribution) !== true) {
            return;
        }
        remove_action('wp_head', 'rel_canonical');
        echo '<link rel="canonical" href="' . esc_url($attribution->sourceUrl) . '" />' . "\n";
    }
}
