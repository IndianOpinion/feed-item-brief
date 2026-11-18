<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Creates and checks the two tables (section 6). After dbDelta every
 * expected column is checked with SHOW COLUMNS; a missing column switches
 * the plugin off with an admin notice (lesson 15).
 */
final class Installer
{
    public const DB_VERSION = '1';
    public const VERSION_OPTION = 'feed_item_brief_db_version';
    public const ERROR_OPTION = 'feed_item_brief_schema_error';

    public const ITEM_COLUMNS = [
        'id', 'adapter', 'source_key', 'key_hash', 'guid_hash', 'url', 'guid', 'feed_url', 'title',
        'source_name', 'byline', 'language', 'claimed_at', 'resolved_at', 'date_reason', 'body_class',
        'mode', 'status', 'reason', 'failures', 'duplicate_of', 'post_id', 'attempts', 'calls',
        'lease_token', 'lease_expires', 'not_before', 'body_text', 'body_html', 'body_hash', 'meta',
        'result', 'created_at', 'updated_at', 'final_at',
    ];

    public const DAILY_COLUMNS = ['day', 'calls'];

    /**
     * @return list<string>
     */
    public static function schema(\wpdb $db): array
    {
        $charset = $db->get_charset_collate();
        $items = WpdbRepository::itemsTable($db);
        $daily = WpdbRepository::dailyTable($db);

        return [
            "CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  adapter varchar(64) NOT NULL DEFAULT '',
  source_key varchar(191) NOT NULL DEFAULT '',
  key_hash char(64) NOT NULL DEFAULT '',
  guid_hash char(64) NOT NULL DEFAULT '',
  url text NOT NULL,
  guid text NOT NULL,
  feed_url text NOT NULL,
  title text NOT NULL,
  source_name varchar(255) NOT NULL DEFAULT '',
  byline text NOT NULL,
  language varchar(35) NOT NULL DEFAULT '',
  claimed_at bigint(20) DEFAULT NULL,
  resolved_at bigint(20) DEFAULT NULL,
  date_reason varchar(191) NOT NULL DEFAULT '',
  body_class varchar(20) NOT NULL DEFAULT '',
  mode varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  reason varchar(191) NOT NULL DEFAULT '',
  failures text NOT NULL,
  duplicate_of bigint(20) unsigned DEFAULT NULL,
  post_id bigint(20) unsigned DEFAULT NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  calls int(11) NOT NULL DEFAULT 0,
  lease_token varchar(64) DEFAULT NULL,
  lease_expires bigint(20) DEFAULT NULL,
  not_before bigint(20) NOT NULL DEFAULT 0,
  body_text longtext NOT NULL,
  body_html longtext NOT NULL,
  body_hash char(64) NOT NULL DEFAULT '',
  meta longtext NOT NULL,
  result longtext NOT NULL,
  created_at bigint(20) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  final_at bigint(20) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY key_hash (key_hash),
  KEY adapter_guid (adapter,guid_hash),
  KEY queue (status,not_before),
  KEY post_id (post_id),
  KEY created_at (created_at)
) {$charset};",
            "CREATE TABLE {$daily} (
  day char(10) NOT NULL,
  calls int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) {$charset};",
        ];
    }

    /**
     * Run on activation and whenever the stored version differs.
     */
    public static function maybeInstall(\wpdb $db): void
    {
        if (get_option(self::VERSION_OPTION) === self::DB_VERSION && get_option(self::ERROR_OPTION, '') === '') {
            return;
        }
        self::install($db);
    }

    public static function install(\wpdb $db): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(self::schema($db));
        $missing = self::missingColumns($db);
        if ($missing !== []) {
            update_option(self::ERROR_OPTION, implode(', ', $missing), false);

            return;
        }
        delete_option(self::ERROR_OPTION);
        update_option(self::VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * @return list<string> table.column for every expected column not present
     */
    public static function missingColumns(\wpdb $db): array
    {
        $missing = [];
        $tables = [
            WpdbRepository::itemsTable($db) => self::ITEM_COLUMNS,
            WpdbRepository::dailyTable($db) => self::DAILY_COLUMNS,
        ];
        foreach ($tables as $table => $columns) {
            $present = $db->get_col('SHOW COLUMNS FROM `' . self::ident($table) . '`', 0);
            $present = array_map('strval', is_array($present) ? $present : []);
            foreach ($columns as $column) {
                if (!in_array($column, $present, true)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        return $missing;
    }

    public static function schemaError(): string
    {
        $error = get_option(self::ERROR_OPTION, '');

        return is_string($error) ? $error : '';
    }

    public static function drop(\wpdb $db): void
    {
        $db->query('DROP TABLE IF EXISTS `' . self::ident(WpdbRepository::itemsTable($db)) . '`');
        $db->query('DROP TABLE IF EXISTS `' . self::ident(WpdbRepository::dailyTable($db)) . '`');
    }

    /**
     * A table name for a backtick-quoted identifier: only the characters a
     * WordPress table prefix may hold.
     */
    private static function ident(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_$]/', '', $name);
    }
}
