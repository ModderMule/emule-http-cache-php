<?php

declare(strict_types=1);

namespace EMule\HttpCache\Storage;

/**
 * Per-key, per-UTC-day upload quota.
 *
 * One small counter file per key per day under var/, guarded with flock() so
 * two concurrent POSTs cannot both read the same total and each write it back.
 * A quota of 0 means unlimited.
 */
class Quota extends StorageArea
{
    /**
     * Reserve bytes against today's allowance for a key.
     *
     * Returns false when the reservation would exceed the quota, in which case
     * nothing is charged.
     */
    public function consume(string $keyId, int $bytes): bool
    {
        $limit = $this->config->quotaFor($keyId);
        if ($limit <= 0) {
            return true;
        }

        $path = $this->counterPath($keyId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            // A broken var/ must not silently disable the quota.
            return false;
        }

        return $this->withLockedCounter(
            $path,
            static fn (int $used): ?int => ($used + $bytes) <= $limit ? $used + $bytes : null,
        );
    }

    /** Bytes already charged to a key today. */
    public function used(string $keyId): int
    {
        $raw = @file_get_contents($this->counterPath($keyId));

        return $raw === false ? 0 : (int) trim($raw);
    }

    /** Give back a reservation that turned out not to be used (a failed ingest). */
    public function refund(string $keyId, int $bytes): void
    {
        if ($this->config->quotaFor($keyId) <= 0 || $bytes <= 0) {
            return;
        }

        $this->withLockedCounter(
            $this->counterPath($keyId),
            static fn (int $used): int => max(0, $used - $bytes),
        );
    }

    // -- internals ------------------------------------------------------------

    /**
     * Read the counter under an exclusive lock, hand it to $mutate, and write
     * back whatever comes out. A null from $mutate means "leave it alone", and
     * is what a refused reservation returns.
     *
     * $mutate takes the current total and returns the new one, or null.
     */
    protected function withLockedCounter(string $path, \Closure $mutate): bool
    {
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);

            return false;
        }

        $used = (int) trim((string) stream_get_contents($fh));
        $next = $mutate($used);

        if ($next !== null) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $next);
            fflush($fh);
        }

        flock($fh, LOCK_UN);
        fclose($fh);

        return $next !== null;
    }

    protected function counterPath(string $keyId): string
    {
        // The key id comes from config, not from the request, but it still ends up
        // in a path — keep it to a safe alphabet regardless.
        $safe = preg_replace('/[^A-Za-z0-9._-]/u', '_', $keyId) ?? 'key';

        return $this->config->varDir . '/quota-' . $safe . '-' . gmdate('Ymd') . '.txt';
    }
}
