<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

/**
 * The install marker, var/install.json.
 *
 * It records that *this* config.php was machine-generated and whether its key
 * has been shown yet. It deliberately holds no secret: the key lives in
 * config.php and nowhere else.
 *
 * Disclosure is gated on this file rather than on config.php, so a config
 * written by hand — which has no marker — can never be read back over HTTP.
 */
class InstallState
{
    public function __construct(
        public readonly int $generatedAt,
        public readonly string $keyId,
        public readonly ?int $claimedAt = null,
    ) {
    }

    public function isClaimed(): bool
    {
        return $this->claimedAt !== null;
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $generatedAt = (int) ($raw['generatedAt'] ?? 0);
        $keyId = (string) ($raw['keyId'] ?? '');

        if ($generatedAt <= 0 || $keyId === '') {
            return null;
        }

        $claimedAt = $raw['claimedAt'] ?? null;

        return new self($generatedAt, $keyId, is_numeric($claimedAt) ? (int) $claimedAt : null);
    }

    /** @return array<string, mixed> the on-disk shape */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'keyId' => $this->keyId,
            'claimedAt' => $this->claimedAt,
        ];
    }

    public function claimedNow(int $now): self
    {
        return new self($this->generatedAt, $this->keyId, $now);
    }
}
