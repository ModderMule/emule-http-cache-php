<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * The metadata sidecar of one stored chunk.
 *
 * Written next to the blob as <id>.json, which keeps the store self-describing:
 * a GC sweep, or an operator with nothing but a shell, never needs a database.
 */
class ChunkMeta
{
    public function __construct(
        public readonly string $id,
        public readonly int $size,
        public readonly string $sha256,
        public readonly string $ownerKeyId,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {
    }

    /** Rebuild from a decoded sidecar, tolerating keys an older version omitted. */
    public static function fromArray(array $raw, string $fallbackId): self
    {
        return new self(
            id: (string) ($raw['id'] ?? $fallbackId),
            size: (int) ($raw['size'] ?? 0),
            sha256: (string) ($raw['sha256'] ?? ''),
            ownerKeyId: (string) ($raw['ownerKeyId'] ?? ''),
            createdAt: (int) ($raw['createdAt'] ?? 0),
            expiresAt: (int) ($raw['expiresAt'] ?? 0),
        );
    }

    /** @return array<string, mixed> the on-disk shape */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'ownerKeyId' => $this->ownerKeyId,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ];
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
