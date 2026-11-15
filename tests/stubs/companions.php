<?php

/**
 * Stand-ins for the public functions of the optional gen-queue and
 * spend-meter plugins. SPEND_METER_API stays undefined, so the plugin
 * treats spend-meter as absent unless a test injects its own meter.
 */

declare(strict_types=1);

if (!function_exists('gen_queue_enqueue')) {
    function gen_queue_enqueue(string $type, array $payload, array $options = []): mixed
    {
        if (!empty($GLOBALS['fib_test_refuse'])) {
            return new \WP_Error('refused', 'Queue full');
        }
        $GLOBALS['fib_test_jobs'][] = [$type, $payload, $options];

        return count($GLOBALS['fib_test_jobs']);
    }
}
if (!function_exists('spend_meter_pre_spend')) {
    function spend_meter_pre_spend(array $args): mixed
    {
        $answer = $GLOBALS['fib_test_spend'] ?? null;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
