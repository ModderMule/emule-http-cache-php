<?php

declare(strict_types=1);

namespace EMule\HttpCache\Storage;

/**
 * Filesystem blob store.
 *
 * Layout: storage/<first two hex chars>/<id>.bin  — the ciphertext
 *         storage/<first two hex chars>/<id>.json — its metadata sidecar
 *
 * The 256-way fan-out keeps directory sizes sane; the sidecar keeps the store
 * self-describing, so a GC sweep or an operator never needs a database.
 *
 * Writes go to a temp file and are renamed into place, so a reader can never
 * observe a half-written chunk (rename(2) is atomic within a filesystem).
 */
class Store extends StorageArea
{
    /** Read/write slice size. 1 MiB in, 512 KiB out — never buffer a whole chunk. */
    protected const READ_CHUNK = 1_048_576;
    protected const SEND_CHUNK = 524_288;

    /** A fresh, unguessable 128-bit chunk id as 32 lowercase hex chars. */
    public function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/u', $id) === 1;
    }

    public function blobPath(string $id): string
    {
        return $this->pathFor($id, 'bin');
    }

    public function metaPath(string $id): string
    {
        return $this->pathFor($id, 'json');
    }

    /**
     * Metadata for a stored chunk, or null when it does not exist or has expired.
     *
     * An expired chunk is reported as absent but is not deleted here — a GET must
     * not do write work. Gc::sweep() reclaims it.
     */
    public function meta(string $id): ?ChunkMeta
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $raw = $this->readJsonFile($this->metaPath($id));
        if ($raw === null) {
            return null;
        }

        $meta = ChunkMeta::fromArray($raw, $id);

        if ($meta->isExpired(time()) || !is_file($this->blobPath($id))) {
            return null;
        }

        return $meta;
    }

    /**
     * True when writing $bytes more would still leave Config::$minFreeBytes free.
     *
     * Fails open when disk_free_space() cannot answer — open_basedir and some
     * container runtimes block it, and a host like that must keep accepting
     * uploads rather than refuse every single one.
     */
    public function hasRoomFor(int $bytes): bool
    {
        if ($this->config->minFreeBytes <= 0) {
            return true;
        }

        $free = @disk_free_space($this->config->storageDir);
        if ($free === false) {
            return true;
        }

        return ($free - $bytes) >= $this->config->minFreeBytes;
    }

    /**
     * Stream the request body into the store.
     *
     * Reads php://input in slices, hashing as it goes, so peak memory stays at
     * one slice regardless of chunk size.
     */
    public function ingestRequestBody(string $ownerKeyId, int $ttlSeconds, ?int $declaredLength): IngestResult
    {
        $max = $this->config->maxChunkSize;

        if ($declaredLength !== null && $declaredLength > $max) {
            return IngestResult::failure(413, 'chunk exceeds maxChunkSize');
        }

        $id = $this->newId();
        $dir = dirname($this->blobPath($id));
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return IngestResult::failure(507, 'cannot create storage directory');
        }

        $tmp = $dir . '/.tmp-' . $id;
        $in = fopen('php://input', 'rb');
        $out = @fopen($tmp, 'wb');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);

            return IngestResult::failure(507, 'cannot open temporary file');
        }

        $hash = hash_init('sha256');
        $total = 0;

        while (!feof($in)) {
            $buf = fread($in, self::READ_CHUNK);
            if ($buf === false) {
                break;
            }
            if ($buf === '') {
                continue;
            }

            // Byte accounting over binary ciphertext: strlen(), never mb_strlen().
            $total += strlen($buf);
            if ($total > $max) {
                fclose($in);
                fclose($out);
                @unlink($tmp);

                return IngestResult::failure(413, 'chunk exceeds maxChunkSize');
            }

            hash_update($hash, $buf);
            if (fwrite($out, $buf) === false) {
                fclose($in);
                fclose($out);
                @unlink($tmp);

                return IngestResult::failure(507, 'write failed');
            }
        }

        fclose($in);
        fclose($out);

        if ($total === 0) {
            @unlink($tmp);

            return IngestResult::failure(400, 'empty body');
        }

        // A short body means the upload was cut off; storing it would hand out a
        // URL to a truncated chunk that every downloader would then fail on.
        if ($declaredLength !== null && $total !== $declaredLength) {
            @unlink($tmp);

            return IngestResult::failure(400, 'body length does not match Content-Length');
        }

        $now = time();
        $meta = new ChunkMeta($id, $total, hash_final($hash), $ownerKeyId, $now, $now + $ttlSeconds);

        if (@file_put_contents($this->metaPath($id), json_encode($meta->toArray(), JSON_THROW_ON_ERROR)) === false) {
            @unlink($tmp);

            return IngestResult::failure(507, 'cannot write metadata');
        }

        if (!@rename($tmp, $this->blobPath($id))) {
            @unlink($tmp);
            @unlink($this->metaPath($id));

            return IngestResult::failure(507, 'cannot commit chunk');
        }

        return IngestResult::committed($meta);
    }

    /** True while the blob or its sidecar is still on disk, expired or not. */
    public function exists(string $id): bool
    {
        return self::isValidId($id)
            && (is_file($this->blobPath($id)) || is_file($this->metaPath($id)));
    }

    /** Remove a chunk and its sidecar. False when it was not there, or would not go. */
    public function delete(string $id): bool
    {
        if (!self::isValidId($id)) {
            return false;
        }

        $blob = $this->blobPath($id);
        $meta = $this->metaPath($id);

        if (!is_file($blob) && !is_file($meta)) {
            return false;
        }

        // Report whether the files actually went, not merely that they were
        // there. A shard directory the current user cannot write to — a sweep
        // run as someone other than the web server user — leaves them in place,
        // and counting those would have Gc report the same reclaim forever.
        $blobGone = !is_file($blob) || @unlink($blob);
        $metaGone = !is_file($meta) || @unlink($meta);

        return $blobGone && $metaGone;
    }

    /** Copy the bytes $from..$to (both inclusive) of a stored blob to the output. */
    public function streamRange(string $id, int $from, int $to): void
    {
        $fh = @fopen($this->blobPath($id), 'rb');
        if ($fh === false) {
            return;
        }

        fseek($fh, $from);
        $remaining = $to - $from + 1;

        while ($remaining > 0 && !feof($fh)) {
            $buf = fread($fh, (int) min(self::SEND_CHUNK, $remaining));
            if ($buf === false || $buf === '') {
                break;
            }
            echo $buf;

            // Byte accounting again: this is binary, mb_strlen() would be wrong.
            $remaining -= strlen($buf);

            // Keep memory flat on large chunks and notice a disconnected client.
            if (connection_aborted() !== 0) {
                break;
            }
        }

        fclose($fh);
    }

    /** @return list<string> every chunk id currently on disk, expired or not */
    public function allIds(): array
    {
        $ids = [];

        foreach ($this->shards() as $shard) {
            $entries = @scandir($this->shardPath($shard));
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '.json')) {
                    continue;
                }

                $id = mb_substr($entry, 0, -5);
                if (self::isValidId($id)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** Expiry timestamp straight off disk, ignoring the "expired reads as absent" rule. */
    public function rawExpiresAt(string $id): ?int
    {
        $raw = $this->readJsonFile($this->metaPath($id));

        return $raw === null ? null : (int) ($raw['expiresAt'] ?? 0);
    }

    // -- internals ------------------------------------------------------------

    protected function pathFor(string $id, string $extension): string
    {
        return $this->config->storageDir . '/' . mb_substr($id, 0, 2) . '/' . $id . '.' . $extension;
    }
}
