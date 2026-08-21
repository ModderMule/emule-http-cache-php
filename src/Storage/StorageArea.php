<?php

declare(strict_types=1);

namespace EMule\HttpCache\Storage;

use EMule\HttpCache\Config;

/**
 * Shared filesystem plumbing for the storage/ and var/ trees.
 *
 * Store, Gc and Quota all walk the same 256-way hex fan-out, read the same JSON
 * sidecars, and reap the same kinds of stale file. That logic lives here once
 * rather than being copied into each of them.
 */
abstract class StorageArea
{
    public function __construct(protected readonly Config $config)
    {
    }

    /** @return list<string> the two-hex-char shard directories under storageDir */
    protected function shards(): array
    {
        $entries = @scandir($this->config->storageDir);
        if ($entries === false) {
            return [];
        }

        $shards = [];
        foreach ($entries as $entry) {
            if (mb_strlen($entry) === 2 && ctype_xdigit($entry)) {
                $shards[] = $entry;
            }
        }

        return $shards;
    }

    protected function shardPath(string $shard): string
    {
        return $this->config->storageDir . '/' . $shard;
    }

    /** @return array<string, mixed>|null null when the file is missing or malformed */
    protected function readJsonFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Delete files in $dir whose name starts with $prefix and whose mtime is
     * older than $maxAge seconds. Returns how many went.
     */
    protected function reapByPrefix(string $dir, string $prefix, int $maxAge, int $now): int
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }

        $removed = 0;
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }

            $path = $dir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) > $maxAge && @unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
