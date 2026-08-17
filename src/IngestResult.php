<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * Outcome of streaming a request body into the store.
 *
 * Either a committed chunk or an HTTP status plus message, never both — hence
 * the named constructors rather than a shape the caller assembles by hand.
 */
class IngestResult
{
    protected function __construct(
        public readonly bool $ok,
        public readonly ?ChunkMeta $meta = null,
        public readonly int $status = 0,
        public readonly string $error = '',
    ) {
    }

    public static function committed(ChunkMeta $meta): self
    {
        return new self(true, $meta);
    }

    public static function failure(int $status, string $error): self
    {
        return new self(false, null, $status, $error);
    }
}
