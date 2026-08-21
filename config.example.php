<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * eMule HTTP Cache — configuration.
 *
 * Copy to config.php and edit, or open /install in a browser and let the
 * installer write it for you. config.php wins when both exist; without it the
 * server falls back to this file for CLI tools, but refuses to serve HTTP.
 *
 * The installer builds config.php out of this file, so these comments survive
 * into your real config. It rewrites the values by key name and replaces the
 * sample key id and the sample secret in the apiKeys block below. Rename either
 * of those and tests/InstallTest.php is what tells you.
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

            // false revokes this uploader without deleting the entry, so you
            // can put it back later and still know whose it was. A revoked key
            // loses DELETE too — it stops being a credential entirely.
            'enabled' => true,
        ],

        // Add as many as you like. The id is yours to choose; it labels the
        // chunk's owner and its counter in var/quota-<id>-<date>.txt, so keep
        // it to [A-Za-z0-9._-]. "anonymous" is reserved and will be ignored.
        //
        // 'laptop'  => ['secret' => '…', 'quotaBytesPerDay' => 5_368_709_120],
        // 'seedbox' => ['secret' => '…', 'enabled' => false],
    ],

    // Accept uploads with no API key at all. Anonymous uploads are owned by the
    // reserved key id "anonymous", which nobody can authenticate as — so they
    // cannot be deleted through the API and only ever lapse at their TTL. A
    // *wrong* key is still a 401 here; only an absent one falls through.
    'openUpload' => false,

    // Daily allowance for anonymous uploads, in bytes, when openUpload is on.
    // 0 = unlimited, which on an open server means "please fill my disk".
    'openUploadQuotaBytesPerDay' => 10_737_418_240,   // 10 GiB

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
