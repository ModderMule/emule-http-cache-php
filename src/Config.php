<?php

declare(strict_types=1);

namespace EMule\HttpCache;

use EMule\HttpCache\Security\ApiKey;

/**
 * Immutable server configuration, loaded from config.php.
 *
 * config.php is deliberately a plain PHP file returning an array rather than
 * .env/ini parsing: it keeps the dependency count at zero and lets an operator
 * compute values (paths, quotas) if they want to.
 */
class Config
{
    /** @param array<string, ApiKey> $apiKeys keyed by key id */
    protected function __construct(
        public readonly string $baseDir,
        public readonly string $storageDir,
        public readonly string $varDir,
        public readonly array $apiKeys,
        public readonly int $maxChunkSize,
        /** Free-space floor on the storage volume; 0 disables the check. */
        public readonly int $minFreeBytes,
        public readonly int $defaultTtl,
        public readonly int $maxTtl,
        public readonly ?string $publicBaseUrl,
        public readonly float $gcProbability,
    ) {
    }

    public static function load(string $baseDir): self
    {
        $file = $baseDir . '/config.php';
        if (!is_file($file)) {
            $file = $baseDir . '/config.example.php';
        }

        $raw = (array) require $file;

        $storageDir = self::absolutePath($baseDir, (string) ($raw['storageDir'] ?? 'storage'));
        $varDir = self::absolutePath($baseDir, (string) ($raw['varDir'] ?? 'var'));

        $keys = [];
        foreach ((array) ($raw['apiKeys'] ?? []) as $keyId => $spec) {
            if (is_string($spec)) {
                $spec = ['secret' => $spec];
            }
            $secret = (string) ($spec['secret'] ?? '');
            if ($secret === '') {
                continue;
            }
            $keys[(string) $keyId] = new ApiKey($secret, (int) ($spec['quotaBytesPerDay'] ?? 0));
        }

        $publicBaseUrl = $raw['publicBaseUrl'] ?? null;
        if (is_string($publicBaseUrl)) {
            $publicBaseUrl = rtrim($publicBaseUrl, '/');
            if ($publicBaseUrl === '') {
                $publicBaseUrl = null;
            }
        } else {
            $publicBaseUrl = null;
        }

        return new self(
            baseDir: $baseDir,
            storageDir: $storageDir,
            varDir: $varDir,
            apiKeys: $keys,
            maxChunkSize: (int) ($raw['maxChunkSize'] ?? 10_485_760),
            // A config predating this key means no floor, not a floor of zero bytes.
            minFreeBytes: max(0, (int) ($raw['minFreeBytes'] ?? 0)),
            defaultTtl: (int) ($raw['defaultTtl'] ?? 172_800),
            maxTtl: (int) ($raw['maxTtl'] ?? 604_800),
            publicBaseUrl: $publicBaseUrl,
            gcProbability: (float) ($raw['gcProbability'] ?? 0.01),
        );
    }

    /** Clamp a client-requested TTL into the configured window. */
    public function clampTtl(?int $requested): int
    {
        if ($requested === null || $requested <= 0) {
            return $this->defaultTtl;
        }

        return min($requested, $this->maxTtl);
    }

    public function quotaFor(string $keyId): int
    {
        return ($this->apiKeys[$keyId] ?? null)?->quotaBytesPerDay ?? 0;
    }

    // -- internals ------------------------------------------------------------

    protected static function absolutePath(string $baseDir, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#u', $path) === 1)) {
            return rtrim($path, '/');
        }

        return rtrim($baseDir . '/' . $path, '/');
    }
}
