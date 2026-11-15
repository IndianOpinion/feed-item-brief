<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * An importer adapter (section 5.2). Register one on
 * feed_item_brief_register_adapters with $registry->add($adapter).
 */
interface Adapter
{
    /** Slug, lower case, 1 to 40 characters. */
    public function id(): string;

    /** Shown in the admin. */
    public function label(): string;

    /** Hook into the importer. Called once after registration. */
    public function boot(): void;

    /** Default post author for a source, or null for the site default. */
    public function defaultAuthor(string $sourceKey): ?int;

    /** Time zone for dates without one, or null for UTC. */
    public function timezone(string $sourceKey): ?string;
}
