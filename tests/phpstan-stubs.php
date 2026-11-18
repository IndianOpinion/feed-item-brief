<?php

// Signatures of optional companion plugins' public functions, for static
// analysis only. The plugin calls them only after function_exists().
// spend-meter is reached through its hooks only and needs no stub.

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $args
 */
function gen_queue_enqueue(string $type, array $payload, array $args = []): mixed
{
    // Documented to return the job id; the plugin still checks the value.
    return 0;
}

/**
 * @param array<string, mixed> $args
 */
function gen_queue_register_type(string $type, callable $handler, array $args = []): void
{
}
