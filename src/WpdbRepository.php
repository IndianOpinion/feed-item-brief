<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\StorageException;

/**
 * Repository on the WordPress database. Prepared statements only.
 */
final class WpdbRepository implements Repository
{
    private const JSON_COLUMNS = ['byline', 'failures', 'meta', 'result'];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public static function itemsTable(\wpdb $db): string
    {
        return $db->prefix . 'feed_item_brief_items';
    }

    public static function dailyTable(\wpdb $db): string
    {
        return $db->prefix . 'feed_item_brief_daily';
    }

    private function items(): string
    {
        return self::itemsTable($this->db);
    }

    private function daily(): string
    {
        return self::dailyTable($this->db);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function encode(array $row): array
    {
        foreach ($row as $key => $value) {
            if (in_array($key, self::JSON_COLUMNS, true) && is_array($value)) {
                $row[$key] = (string) wp_json_encode($value);
            } elseif (is_bool($value)) {
                $row[$key] = $value ? 1 : 0;
            }
        }

        return $row;
    }

    public function insert(array $row): int
    {
        $result = $this->db->insert($this->items(), self::encode($row));
        if ($result === false) {
            throw new StorageException('Insert failed: ' . $this->db->last_error);
        }

        return (int) $this->db->insert_id;
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $fields = self::encode($fields);
        $sets = [];
        $values = [];
        foreach ($fields as $column => $value) {
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new StorageException('Bad column name.');
            }
            if ($value === null) {
                $sets[] = "`{$column}` = NULL";
                continue;
            }
            $sets[] = is_int($value) ? "`{$column}` = %d" : "`{$column}` = %s";
            $values[] = is_int($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }
        $values[] = $id;
        $sql = 'UPDATE `' . $this->items() . '` SET ' . implode(', ', $sets) . ' WHERE id = %d';
        $result = $this->db->query((string) $this->db->prepare($sql, ...$values));
        if ($result === false) {
            throw new StorageException('Update failed: ' . $this->db->last_error);
        }
    }

    public function find(int $id): ?Record
    {
        return $this->one($this->db->prepare('SELECT * FROM `' . $this->items() . '` WHERE id = %d', $id));
    }

    public function findByKey(string $keyHash): ?Record
    {
        return $this->one($this->db->prepare(
            'SELECT * FROM `' . $this->items() . '` WHERE key_hash = %s AND duplicate_of IS NULL AND status <> %s ORDER BY id ASC LIMIT 1',
            $keyHash,
            Status::REJECTED
        ));
    }

    public function findByGuid(string $adapter, string $guidHash): ?Record
    {
        return $this->one($this->db->prepare(
            'SELECT * FROM `' . $this->items() . '` WHERE adapter = %s AND guid_hash = %s AND duplicate_of IS NULL AND status <> %s ORDER BY id ASC LIMIT 1',
            $adapter,
            $guidHash,
            Status::REJECTED
        ));
    }

    public function findByPost(int $postId): ?Record
    {
        return $this->one($this->db->prepare('SELECT * FROM `' . $this->items() . '` WHERE post_id = %d ORDER BY id ASC LIMIT 1', $postId));
    }

    public function findSeen(int $firstId, string $adapter, string $sourceKey, string $bodyHash): ?Record
    {
        return $this->one($this->db->prepare(
            'SELECT * FROM `' . $this->items() . '` WHERE (id = %d OR duplicate_of = %d) AND adapter = %s AND source_key = %s AND body_hash = %s ORDER BY id ASC LIMIT 1',
            $firstId,
            $firstId,
            $adapter,
            $sourceKey,
            $bodyHash
        ));
    }

    public function claim(int $id, int $now, string $token, int $leaseSeconds): ?Record
    {
        $updated = $this->db->query((string) $this->db->prepare(
            'UPDATE `' . $this->items() . '` SET lease_token = %s, lease_expires = %d, updated_at = %d'
            . ' WHERE id = %d AND final_at IS NULL AND (lease_token IS NULL OR lease_expires < %d)',
            $token,
            $now + $leaseSeconds,
            $now,
            $id,
            $now
        ));

        return $updated === 1 ? $this->find($id) : null;
    }

    public function claimNext(int $now, string $token, int $leaseSeconds): ?Record
    {
        $statuses = Status::CLAIMABLE;
        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        for ($try = 0; $try < 5; $try++) {
            $sql = $this->db->prepare(
                'SELECT id FROM `' . $this->items() . '` WHERE final_at IS NULL AND status IN (' . $placeholders . ')'
                . ' AND not_before <= %d AND (lease_token IS NULL OR lease_expires < %d)'
                . ' ORDER BY resolved_at DESC, id ASC LIMIT 1',
                ...[...$statuses, $now, $now]
            );
            $id = $this->db->get_var((string) $sql);
            if ($id === null) {
                return null;
            }
            $claimed = $this->claim((int) $id, $now, $token, $leaseSeconds);
            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    public function search(array $filters): array
    {
        $where = ['1 = 1'];
        $values = [];
        foreach (['status', 'reason', 'adapter'] as $key) {
            if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
                $where[] = "`{$key}` = %s";
                $values[] = $filters[$key];
            }
        }
        if (isset($filters['since']) && is_int($filters['since'])) {
            $where[] = 'created_at >= %d';
            $values[] = $filters['since'];
        }
        $limit = isset($filters['limit']) && is_int($filters['limit']) ? max(1, min(500, $filters['limit'])) : 50;
        $offset = isset($filters['offset']) && is_int($filters['offset']) ? max(0, $filters['offset']) : 0;
        $values[] = $limit;
        $values[] = $offset;
        $sql = 'SELECT * FROM `' . $this->items() . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $this->db->get_results((string) $this->db->prepare($sql, ...$values), ARRAY_A);

        return array_map([Record::class, 'fromRow'], is_array($rows) ? array_values($rows) : []);
    }

    public function countByStatus(): array
    {
        $rows = $this->db->get_results('SELECT status, COUNT(*) AS n FROM `' . $this->items() . '` GROUP BY status', ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    public function purgeBodies(int $before): int
    {
        $result = $this->db->query((string) $this->db->prepare(
            "UPDATE `" . $this->items() . "` SET body_text = '', body_html = '' WHERE final_at IS NOT NULL AND final_at < %d AND (body_text <> '' OR body_html <> '')",
            $before
        ));

        return is_int($result) ? $result : 0;
    }

    public function purgeRows(int $before): int
    {
        $result = $this->db->query((string) $this->db->prepare(
            'DELETE FROM `' . $this->items() . '` WHERE post_id IS NULL AND created_at < %d',
            $before
        ));

        return is_int($result) ? $result : 0;
    }

    public function takeCall(string $day, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }
        $created = $this->db->query((string) $this->db->prepare('INSERT IGNORE INTO `' . $this->daily() . '` (day, calls) VALUES (%s, 0)', $day));
        if ($created === false) {
            return false;
        }
        $result = $this->db->query((string) $this->db->prepare(
            'UPDATE `' . $this->daily() . '` SET calls = calls + 1 WHERE day = %s AND calls < %d',
            $day,
            $cap
        ));

        return $result === 1;
    }

    public function refundCall(string $day): void
    {
        $this->db->query((string) $this->db->prepare('UPDATE `' . $this->daily() . '` SET calls = calls - 1 WHERE day = %s AND calls > 0', $day));
    }

    public function callsOn(string $day): int
    {
        return (int) $this->db->get_var((string) $this->db->prepare('SELECT calls FROM `' . $this->daily() . '` WHERE day = %s', $day));
    }

    public function purgeDays(string $before): int
    {
        $result = $this->db->query((string) $this->db->prepare('DELETE FROM `' . $this->daily() . '` WHERE day < %s', $before));

        return is_int($result) ? $result : 0;
    }

    private function one(?string $sql): ?Record
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        $row = $this->db->get_row($sql, ARRAY_A);

        return is_array($row) ? Record::fromRow($row) : null;
    }
}
