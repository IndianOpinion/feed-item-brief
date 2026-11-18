<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            /** @var list<string> */
            public static array $out = [];

            public static function line(string $message = ''): void
            {
                self::$out[] = $message;
            }

            public static function success(string $message): void
            {
                self::$out[] = 'Success: ' . $message;
            }

            public static function error(string $message): void
            {
                throw new \RuntimeException('Error: ' . $message);
            }

            public static function add_command(string $name, mixed $callable): void
            {
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists('WP_CLI\\Utils\\format_items')) {
        /**
         * @param list<array<string, mixed>> $items
         * @param list<string> $fields
         */
        function format_items(string $format, array $items, array $fields): void
        {
            \WP_CLI::$out[] = $format === 'json' ? (string) json_encode($items) : count($items) . ' rows';
        }
    }
}
