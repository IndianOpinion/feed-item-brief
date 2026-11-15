<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Model;

use IndianOpinion\FeedItemBrief\Exception\ProviderException;

/**
 * One structured model call. The plugin's only seam to a provider.
 */
interface ModelClient
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema
     * @throws ProviderException
     */
    public function complete(array $messages, array $schema, int $timeoutSeconds): ModelResponse;
}
