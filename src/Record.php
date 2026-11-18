<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

/**
 * One stored submission. Read-only view of a database row.
 */
final class Record
{
    /**
     * @param list<string> $bylines
     * @param list<string> $failures
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly int $id,
        public readonly string $adapter,
        public readonly string $sourceKey,
        public readonly string $keyHash,
        public readonly string $guidHash,
        public readonly string $url,
        public readonly string $guid,
        public readonly string $feedUrl,
        public readonly string $title,
        public readonly string $sourceName,
        public readonly array $bylines,
        public readonly string $language,
        public readonly ?int $claimedAt,
        public readonly ?int $resolvedAt,
        public readonly string $dateReason,
        public readonly string $bodyClass,
        public readonly string $mode,
        public readonly string $status,
        public readonly string $reason,
        public readonly array $failures,
        public readonly ?int $duplicateOf,
        public readonly ?int $postId,
        public readonly int $attempts,
        public readonly int $calls,
        public readonly ?string $leaseToken,
        public readonly ?int $leaseExpires,
        public readonly int $notBefore,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
        public readonly string $bodyHash,
        public readonly array $meta,
        public readonly ?array $result,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?int $finalAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int = static fn (string $k): int => (int) ($row[$k] ?? 0);
        $nint = static fn (string $k): ?int => isset($row[$k]) && $row[$k] !== '' ? (int) $row[$k] : null;
        $str = static fn (string $k): string => is_scalar($row[$k] ?? null) ? (string) $row[$k] : '';
        $json = static function (string $k) use ($row): ?array {
            $value = $row[$k] ?? null;
            if (!is_string($value) || $value === '') {
                return null;
            }
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        };
        $list = static fn (?array $v): array => $v === null ? [] : array_values(array_filter($v, 'is_string'));

        $meta = $json('meta') ?? [];
        /** @var array<string, mixed> $meta */

        return new self(
            $int('id'),
            $str('adapter'),
            $str('source_key'),
            $str('key_hash'),
            $str('guid_hash'),
            $str('url'),
            $str('guid'),
            $str('feed_url'),
            $str('title'),
            $str('source_name'),
            $list($json('byline')),
            $str('language'),
            $nint('claimed_at'),
            $nint('resolved_at'),
            $str('date_reason'),
            $str('body_class'),
            $str('mode'),
            $str('status'),
            $str('reason'),
            $list($json('failures')),
            $nint('duplicate_of'),
            $nint('post_id'),
            $int('attempts'),
            $int('calls'),
            $str('lease_token') !== '' ? $str('lease_token') : null,
            $nint('lease_expires'),
            $int('not_before'),
            $str('body_text'),
            $str('body_html'),
            $str('body_hash'),
            $meta,
            $json('result'),
            $int('created_at'),
            $int('updated_at'),
            $nint('final_at'),
        );
    }

    /**
     * Rebuild the item as it was submitted, with the resolved URL.
     */
    public function item(): Item
    {
        $data = is_array($this->meta['item'] ?? null) ? $this->meta['item'] : [];
        $data['url'] = $this->url;
        $data['title'] = $this->title;
        $data['body'] = $this->bodyHtml !== '' ? $this->bodyHtml : $this->bodyText;
        $data['source_name'] = $this->sourceName;
        $data['byline'] = $this->bylines;
        $data['language'] = $this->language === 'und' ? '' : $this->language;

        return Item::fromArray($data, true);
    }

    public function resolvedDate(): ?ResolvedDate
    {
        return $this->resolvedAt === null ? null : ResolvedDate::fromStored($this->resolvedAt, $this->dateReason, $this->claimedAt);
    }

    public function isFinal(): bool
    {
        return $this->finalAt !== null;
    }

    /** @return list<string> */
    public function notes(): array
    {
        $notes = $this->meta['notes'] ?? [];

        return is_array($notes) ? array_values(array_filter($notes, 'is_string')) : [];
    }
}
