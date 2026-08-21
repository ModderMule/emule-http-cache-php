<?php

declare(strict_types=1);

namespace EMule\HttpCache\Security;

/**
 * One configured uploader credential.
 *
 * Downloads are unauthenticated by design, so a key only ever gates POST and
 * DELETE. A quotaBytesPerDay of 0 means unlimited.
 *
 * A disabled key stays loaded rather than being dropped: its quota counter and
 * the chunks it already owns still have to resolve, it just stops matching.
 */
class ApiKey
{
    public function __construct(
        public readonly string $secret,
        public readonly int $quotaBytesPerDay = 0,
        public readonly bool $enabled = true,
    ) {
    }
}
