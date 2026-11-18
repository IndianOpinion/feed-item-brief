<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Exception;

/**
 * The model call failed. The kind says what to do next:
 * transient (requeue with back-off), auth (pause), credit (pause),
 * config (requeue later, operator must fix settings).
 */
final class ProviderException extends FeedItemBriefException
{
    public const TRANSIENT = 'transient';
    public const AUTH = 'auth';
    public const CREDIT = 'credit';
    public const CONFIG = 'config';

    public function __construct(
        string $message,
        private readonly string $kind = self::TRANSIENT,
        private readonly bool $reachedProvider = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function reachedProvider(): bool
    {
        return $this->reachedProvider;
    }
}
