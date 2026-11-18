<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\StorageException;

/**
 * Storage for records and the daily call counter.
 */
interface Repository
{
    /**
     * @param array<string, mixed> $row column => value; arrays are JSON-encoded
     * @throws StorageException
     */
    public function insert(array $row): int;

    /**
     * @param array<string, mixed> $fields
     * @throws StorageException
     */
    public function update(int $id, array $fields): void;

    public function find(int $id): ?Record;

    /** The first record (lowest id, not itself a duplicate) with this article key hash. */
    public function findByKey(string $keyHash): ?Record;

    /** The first record from this adapter with this guid hash. */
    public function findByGuid(string $adapter, string $guidHash): ?Record;

    public function findByPost(int $postId): ?Record;

    /**
     * A record from this adapter and source pointing at $firstId (or $firstId
     * itself) with this body hash: the same item submitted again.
     */
    public function findSeen(int $firstId, string $adapter, string $sourceKey, string $bodyHash): ?Record;

    /**
     * Claim one record by id with a lease, or null if it is final or leased.
     */
    public function claim(int $id, int $now, string $token, int $leaseSeconds): ?Record;

    /**
     * Claim the next due record with a lease, or null. Newest resolved time
     * first. Safe against overlapping runners.
     */
    public function claimNext(int $now, string $token, int $leaseSeconds): ?Record;

    /**
     * @param array<string, mixed> $filters status, reason, adapter, since (unix), limit, offset
     * @return list<Record>
     */
    public function search(array $filters): array;

    /** @return array<string, int> status => count */
    public function countByStatus(): array;

    /** Clear bodies of records final before $before. Returns rows changed. */
    public function purgeBodies(int $before): int;

    /** Delete records without a post created before $before. */
    public function purgeRows(int $before): int;

    /**
     * Take one call slot for $day if fewer than $cap are used. Atomic.
     */
    public function takeCall(string $day, int $cap): bool;

    /** Give back one slot taken for a call that was never made. */
    public function refundCall(string $day): void;

    public function callsOn(string $day): int;

    public function purgeDays(string $before): int;
}
