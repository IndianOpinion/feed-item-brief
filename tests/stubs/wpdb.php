<?php

/**
 * A minimal wpdb for tests. Without a mysqli connection it records
 * queries; with one (integration tests) it runs them for real.
 */

declare(strict_types=1);

class wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private ?mysqli $link = null)
    {
    }

    private function quote(mixed $value): string
    {
        $text = (string) $value;

        return "'" . ($this->link !== null ? $this->link->real_escape_string($text) : addslashes($text)) . "'";
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;

        return (string) preg_replace_callback('/%%|%d|%s|%f/', function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $value = $args[$i++] ?? null;

            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => $this->quote($value),
            };
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ($this->link === null) {
            return 0;
        }
        try {
            $result = $this->link->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
        if ($result === false) {
            $this->last_error = $this->link->error;

            return false;
        }
        if ($result instanceof mysqli_result) {
            $n = $result->num_rows;
            $result->free();

            return $n;
        }
        $this->rows_affected = $this->link->affected_rows;
        $this->insert_id = (int) $this->link->insert_id;

        return $this->rows_affected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $this->queries[] = $sql;
        if ($this->link === null) {
            return [];
        }
        try {
            $result = $this->link->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return [];
        }
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return array_values($rows);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        return $this->rows($sql);
    }

    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        return $this->rows($sql)[0] ?? null;
    }

    public function get_var(string $sql): ?string
    {
        $row = $this->rows($sql)[0] ?? null;

        return $row === null ? null : (string) array_values($row)[0];
    }

    /**
     * @return list<string>
     */
    public function get_col(string $sql, int $x = 0): array
    {
        return array_map(static fn (array $row): string => (string) array_values($row)[$x], $this->rows($sql));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int|false
    {
        $columns = array_map(static fn (string $c): string => '`' . $c . '`', array_keys($data));
        $values = array_map(fn (mixed $v): string => $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->quote($v)), array_values($data));
        $result = $this->query('INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');

        return $result === false ? false : (int) $result;
    }
}
