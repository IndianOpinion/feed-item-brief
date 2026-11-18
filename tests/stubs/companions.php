<?php

/**
 * Stand-ins for the public functions of the optional gen-queue plugin.
 * spend-meter is reached through hooks only, so tests hook
 * spend_meter_pre_spend themselves. SPEND_METER_API stays undefined, so the
 * plugin treats spend-meter as absent unless a test injects its own meter.
 */

declare(strict_types=1);

if (!function_exists('gen_queue_enqueue')) {
    function gen_queue_enqueue(string $type, array $payload, array $args = []): int
    {
        if (!empty($GLOBALS['fib_test_refuse'])) {
            throw new \RuntimeException('Queue full');
        }
        $GLOBALS['fib_test_jobs'][] = [$type, $payload, $args];

        return count($GLOBALS['fib_test_jobs']);
    }
}
if (!function_exists('gen_queue_register_type')) {
    function gen_queue_register_type(string $type, callable $handler, array $args = []): void
    {
        $GLOBALS['fib_test_types'][$type] = $handler;
    }
}
