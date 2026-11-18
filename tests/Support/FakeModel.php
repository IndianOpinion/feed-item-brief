<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Tests\Support;

use IndianOpinion\FeedItemBrief\Exception\ProviderException;
use IndianOpinion\FeedItemBrief\Model\ModelClient;
use IndianOpinion\FeedItemBrief\Model\ModelResponse;

/**
 * A model client that returns scripted answers and records every call.
 */
final class FakeModel implements ModelClient
{
    /** @var list<array<mixed>|ProviderException> */
    public array $script = [];
    /** @var list<array{messages: list<array{role: string, content: string}>, schema: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param array<mixed>|ProviderException ...$answers
     */
    public function queue(array|ProviderException ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->script[] = $answer;
        }

        return $this;
    }

    public function complete(array $messages, array $schema, int $timeoutSeconds): ModelResponse
    {
        $this->calls[] = ['messages' => $messages, 'schema' => $schema];
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('The fake model has no scripted answer left.');
        }
        if ($next instanceof ProviderException) {
            throw $next;
        }

        return new ModelResponse($next, 'fake/model', 100, 50);
    }

    /**
     * A valid answer.
     *
     * @param list<string> $paragraphs
     * @return array<string, mixed>
     */
    public static function answer(array $paragraphs, string $headline = 'Council approves the new river bridge plan', string $language = 'en'): array
    {
        return ['headline' => $headline, 'summary' => $paragraphs, 'language' => $language, 'insufficient' => false];
    }
}
