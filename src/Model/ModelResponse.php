<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Model;

final class ModelResponse
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
    ) {
    }
}
