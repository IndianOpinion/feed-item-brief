<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Registered adapters. The id "manual" is reserved for the "Brief from URL"
 * screen and WP-CLI.
 */
final class Registry
{
    public const MANUAL = 'manual';

    /** @var array<string, Adapter> */
    private array $adapters = [];

    public function add(Adapter $adapter): bool
    {
        $id = $adapter->id();
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,39}$/', $id) !== 1 || $id === self::MANUAL) {
            _doing_it_wrong(__METHOD__, esc_html(sprintf('Adapter id "%s" is not a valid, unreserved slug.', $id)), '0.1.0');

            return false;
        }
        if (isset($this->adapters[$id])) {
            _doing_it_wrong(__METHOD__, esc_html(sprintf('An adapter with id "%s" is already registered.', $id)), '0.1.0');

            return false;
        }
        $this->adapters[$id] = $adapter;

        return true;
    }

    public function get(string $id): ?Adapter
    {
        return $this->adapters[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $id === self::MANUAL || isset($this->adapters[$id]);
    }

    /** @return array<string, Adapter> */
    public function all(): array
    {
        return $this->adapters;
    }
}
