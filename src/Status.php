<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * Record statuses (section 11). Final statuses end processing; the others
 * wait in the queue.
 */
final class Status
{
    public const QUEUED = 'queued';
    public const PAUSED = 'paused';
    public const PROCESSING = 'processing';
    public const DRAFTED = 'drafted';
    public const DUPLICATE = 'duplicate';
    public const UPDATE = 'update';
    public const STALE = 'stale';
    public const NOT_ARTICLE = 'not_article';
    public const SPONSORED = 'sponsored';
    public const NO_BODY = 'no_body';
    public const PAYWALLED = 'paywalled';
    public const THIN = 'thin';
    public const CHECK_FAILED = 'check_failed';
    public const PROVIDER_FAILED = 'provider_failed';
    public const INSERT_FAILED = 'insert_failed';
    public const CAPPED = 'capped';
    public const SPEND_DENIED = 'spend_denied';
    public const AUTH_FAILED = 'auth_failed';
    public const NO_CREDIT = 'no_credit';
    public const REJECTED = 'rejected';

    /** Statuses the queue runner may claim. */
    public const CLAIMABLE = [
        self::QUEUED, self::PAUSED, self::CAPPED, self::SPEND_DENIED,
        self::AUTH_FAILED, self::NO_CREDIT, self::INSERT_FAILED, self::PROCESSING,
    ];

    /** Statuses an editor may retry from the log. */
    public const RETRYABLE = [
        self::CHECK_FAILED, self::INSERT_FAILED, self::STALE, self::CAPPED, self::PROVIDER_FAILED,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::QUEUED, self::PAUSED, self::PROCESSING, self::DRAFTED, self::DUPLICATE, self::UPDATE,
            self::STALE, self::NOT_ARTICLE, self::SPONSORED, self::NO_BODY, self::PAYWALLED, self::THIN,
            self::CHECK_FAILED, self::PROVIDER_FAILED, self::INSERT_FAILED, self::CAPPED, self::SPEND_DENIED,
            self::AUTH_FAILED, self::NO_CREDIT, self::REJECTED,
        ];
    }
}
