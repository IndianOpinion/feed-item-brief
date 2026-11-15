<?php

// Signatures of optional companion plugins' public functions, for static
// analysis only. The plugin calls them only after function_exists().

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $options
 */
function gen_queue_enqueue(string $type, array $payload, array $options = []): mixed
{
    return null;
}

function gen_queue_register_handler(string $type, callable $handler): void
{
}

/**
 * @param array<string, mixed> $args
 */
function spend_meter_pre_spend(array $args): mixed
{
    return null;
}

/**
 * @param array<string, mixed> $usage
 */
function spend_meter_record(?string $reservationId, array $usage): void
{
}

function spend_meter_release(?string $reservationId): void
{
}
