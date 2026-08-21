<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * eMule HTTP Cache — configuration.
 *
 * Copy to config.php and edit. config.php wins when both exist; without it the
 * server falls back to this file, so a fresh checkout still runs.
 */

return [
    // ------------------------------------------------------------------
    // API keys — uploaders only. Downloads are unauthenticated by design.
    //
    // Generate one with:   php -r 'echo bin2hex(random_bytes(24)), "\n";'
    //
    // quotaBytesPerDay: 0 = unlimited. Counted per UTC day, per key.
    // ------------------------------------------------------------------
    'apiKeys' => [
        'local-test' => [
            'secret' => 'dev-only-change-me-0123456789abcdef',
            'quotaBytesPerDay' => 0,
        ],
    ],

    // Where ciphertext blobs and their metadata sidecars live. Relative paths
    // are resolved against this directory.
    'storageDir' => 'storage',

    // Quota counters and other small runtime state.
    'varDir' => 'var',

    // Largest single chunk the server accepts. An eMule part is 9,728,000 bytes
    // of plaintext, which is 9,728,016 after AES-CBC PKCS#7 padding; 10 MiB
    // leaves room without inviting arbitrary blobs.
    'maxChunkSize' => 10_485_760,

    // Refuse uploads once free disk space on the storage volume would drop below
    // this. 0 = no floor. Complements quotaBytesPerDay: that limits one key per
    // day, this protects the host from every key at once.
    'minFreeBytes' => 1_073_741_824,   // 1 GiB

    // TTL applied when the client sends no X-Chunk-TTL, and the ceiling any
    // requested TTL is clamped to.
    'defaultTtl' => 172_800,   // 48 hours
    'maxTtl' => 604_800,       // 1 week

    // Absolute base URL handed back in the "url" field of a 201. Leave null to
    // derive it from the request; pin it when behind a reverse proxy that
    // rewrites Host, or the URLs sent to peers will point at the wrong place.
    'publicBaseUrl' => null,

    // Chance that a successful upload also runs a bounded expiry sweep. Set to
    // 0.0 and run bin/gc.php from cron on a busy install.
    'gcProbability' => 0.01,
];
