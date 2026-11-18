<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Support;

use IndianOpinion\FeedItemBrief\Exception\StorageException;
use IndianOpinion\FeedItemBrief\Installer;
use IndianOpinion\FeedItemBrief\Record;
use IndianOpinion\FeedItemBrief\Repository;
use IndianOpinion\FeedItemBrief\Status;

/**
 * In-memory repository with the same semantics as the database one:
 * conditional lease claims and a conditional counter increment.
 */
final class MemoryRepository implements Repository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var array<string, int> */
    public array $days = [];
    public bool $failInsert = false;
    private int $next = 1;

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function encode(array $row): array
    {
        foreach (['byline', 'failures', 'meta', 'result'] as $json) {
            if (isset($row[$json]) && is_array($row[$json])) {
                $row[$json] = json_encode($row[$json]);
            }
        }

        return $row;
    }

    public function insert(array $row): int
    {
        if ($this->failInsert) {
            throw new StorageException('Insert failed: test');
        }
        $id = $this->next++;
        $defaults = array_fill_keys(Installer::ITEM_COLUMNS, null);
        foreach (['attempts', 'calls', 'not_before', 'created_at', 'updated_at'] as $int) {
            $defaults[$int] = 0;
        }
        $this->rows[$id] = array_merge($defaults, self::encode($row), ['id' => $id]);

        return $id;
    }

    public function update(int $id, array $fields): void
    {
        if (!isset($this->rows[$id])) {
            throw new StorageException('No such row');
        }
        $this->rows[$id] = array_merge($this->rows[$id], self::encode($fields));
    }

    public function find(int $id): ?Record
    {
        return isset($this->rows[$id]) ? Record::fromRow($this->rows[$id]) : null;
    }

    /**
     * @param callable(array<string, mixed>): bool $where
     */
    private function first(callable $where): ?Record
    {
        foreach ($this->rows as $row) {
            if ($where($row)) {
                return Record::fromRow($row);
            }
        }

        return null;
    }

    public function findByKey(string $keyHash): ?Record
    {
        return $this->first(static fn (array $r): bool => $r['key_hash'] === $keyHash && $r['duplicate_of'] === null && $r['status'] !== Status::REJECTED);
    }

    public function findByGuid(string $adapter, string $guidHash): ?Record
    {
        return $this->first(static fn (array $r): bool => $r['adapter'] === $adapter && $r['guid_hash'] === $guidHash && $r['duplicate_of'] === null && $r['status'] !== Status::REJECTED);
    }

    public function findByPost(int $postId): ?Record
    {
        return $this->first(static fn (array $r): bool => (int) $r['post_id'] === $postId && $r['post_id'] !== null);
    }

    public function findSeen(int $firstId, string $adapter, string $sourceKey, string $bodyHash): ?Record
    {
        return $this->first(static fn (array $r): bool => ((int) $r['id'] === $firstId || (int) $r['duplicate_of'] === $firstId)
            && $r['adapter'] === $adapter && (string) $r['source_key'] === $sourceKey && $r['body_hash'] === $bodyHash);
    }

    public function claim(int $id, int $now, string $token, int $leaseSeconds): ?Record
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row['final_at'] !== null || ($row['lease_token'] !== null && (int) $row['lease_expires'] >= $now)) {
            return null;
        }
        $this->rows[$id]['lease_token'] = $token;
        $this->rows[$id]['lease_expires'] = $now + $leaseSeconds;

        return $this->find($id);
    }

    public function claimNext(int $now, string $token, int $leaseSeconds): ?Record
    {
        $candidates = array_filter($this->rows, static fn (array $r): bool => $r['final_at'] === null
            && in_array($r['status'], Status::CLAIMABLE, true)
            && (int) $r['not_before'] <= $now
            && ($r['lease_token'] === null || (int) $r['lease_expires'] < $now));
        uasort($candidates, static fn (array $a, array $b): int => [(int) $b['resolved_at'], (int) $a['id']] <=> [(int) $a['resolved_at'], (int) $b['id']]);
        foreach ($candidates as $id => $row) {
            $claimed = $this->claim((int) $id, $now, $token, $leaseSeconds);
            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    public function search(array $filters): array
    {
        $out = [];
        foreach (array_reverse($this->rows, true) as $row) {
            foreach (['status', 'reason', 'adapter'] as $key) {
                if (isset($filters[$key]) && $row[$key] !== $filters[$key]) {
                    continue 2;
                }
            }
            if (isset($filters['since']) && (int) $row['created_at'] < (int) $filters['since']) {
                continue;
            }
            $out[] = Record::fromRow($row);
        }

        return $out;
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[(string) $row['status']] = ($out[(string) $row['status']] ?? 0) + 1;
        }

        return $out;
    }

    public function purgeBodies(int $before): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['final_at'] !== null && (int) $row['final_at'] < $before && ((string) $row['body_text'] !== '' || (string) $row['body_html'] !== '')) {
                $this->rows[$id]['body_text'] = '';
                $this->rows[$id]['body_html'] = '';
                $n++;
            }
        }

        return $n;
    }

    public function purgeRows(int $before): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['post_id'] === null && (int) $row['created_at'] < $before) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function takeCall(string $day, int $cap): bool
    {
        $this->days[$day] ??= 0;
        if ($this->days[$day] >= $cap) {
            return false;
        }
        $this->days[$day]++;

        return true;
    }

    public function refundCall(string $day): void
    {
        if (($this->days[$day] ?? 0) > 0) {
            $this->days[$day]--;
        }
    }

    public function callsOn(string $day): int
    {
        return $this->days[$day] ?? 0;
    }

    public function purgeDays(string $before): int
    {
        $n = 0;
        foreach (array_keys($this->days) as $day) {
            if ($day < $before) {
                unset($this->days[$day]);
                $n++;
            }
        }

        return $n;
    }
}
