<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * One configured uploader credential.
 *
 * Downloads are unauthenticated by design, so a key only ever gates POST and
 * DELETE. A quotaBytesPerDay of 0 means unlimited.
 */
class ApiKey
{
    public function __construct(
        public readonly string $secret,
        public readonly int $quotaBytesPerDay = 0,
    ) {
    }
}
