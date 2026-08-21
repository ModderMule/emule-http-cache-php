<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

use EMule\HttpCache\Storage\ChunkMeta;
use EMule\HttpCache\Storage\Store;

/**
 * RFC 9110 §14 byte-range serving for stored chunks.
 *
 * Range support is not decoration: an eMuleQt downloader that loses its
 * connection halfway through a 9.28 MB chunk resumes with
 * `Range: bytes=<offset-16>-`, using the preceding ciphertext block as the CBC
 * IV. Without a correct 206 it would have to start over.
 *
 * Parsing lives in ByteRange; this class only decides which headers to emit.
 */
class RangeResponse
{
    public static function serve(Store $store, ChunkMeta $meta): void
    {
        $size = $meta->size;
        $etag = '"' . mb_substr($meta->sha256, 0, 32) . '"';
        $maxAge = max(0, $meta->expiresAt - time());

        header('Content-Type: application/octet-stream');
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=' . $maxAge . ', immutable');
        header('X-Chunk-Expires: ' . $meta->expiresAt);
        header('X-Content-Type-Options: nosniff');

        $raw = $_SERVER['HTTP_RANGE'] ?? null;
        $range = ByteRange::parse(is_string($raw) ? $raw : null, $size);

        if ($range === null) {
            self::serveWhole($store, $meta, $etag);

            return;
        }

        if (!$range->satisfiable) {
            header('Content-Range: bytes */' . $size);
            Response::status(416);
            header('Content-Length: 0');

            return;
        }

        Response::status(206);
        header('Content-Range: bytes ' . $range->from . '-' . $range->to . '/' . $size);
        header('Content-Length: ' . $range->length());

        if (self::isHead()) {
            return;
        }

        $store->streamRange($meta->id, $range->from, $range->to);
    }

    // -- internals ------------------------------------------------------------

    protected static function serveWhole(Store $store, ChunkMeta $meta, string $etag): void
    {
        // Conditional GET is only meaningful for the full entity here.
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        if (is_string($inm) && trim($inm) === $etag) {
            Response::status(304);

            return;
        }

        Response::status(200);
        header('Content-Length: ' . $meta->size);

        if (self::isHead()) {
            return;
        }

        $store->streamRange($meta->id, 0, $meta->size - 1);
    }

    protected static function isHead(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
    }
}
