<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * A checked summary ready for a draft.
 */
final class Summary
{
    /**
     * @param list<string> $paragraphs
     * @param list<string> $flags
     */
    public function __construct(
        public readonly string $headline,
        public readonly array $paragraphs,
        public readonly string $language,
        public readonly bool $insufficient,
        public readonly array $flags,
        public readonly string $model,
        public readonly int $calledAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline,
            'summary' => $this->paragraphs,
            'language' => $this->language,
            'insufficient' => $this->insufficient,
            'flags' => $this->flags,
            'model' => $this->model,
            'called_at' => $this->calledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['headline'] ?? null) || !is_array($data['summary'] ?? null)) {
            return null;
        }
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return new self(
            $data['headline'],
            $list($data['summary']),
            is_string($data['language'] ?? null) ? $data['language'] : '',
            ($data['insufficient'] ?? false) === true,
            $list($data['flags'] ?? []),
            is_string($data['model'] ?? null) ? $data['model'] : '',
            is_int($data['called_at'] ?? null) ? $data['called_at'] : 0,
        );
    }
}
