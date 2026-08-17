<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

use EMule\HttpCache\Config;

/**
 * eMule HTTP Cache — end-to-end contract test.
 *
 * Exercises the full REST contract over plain HTTP, so it doubles as an
 * executable specification for a non-PHP backend: point it at any
 * implementation and it must pass unchanged.
 *
 *   php tests/smoke.php [baseUrl] [apiKey]
 *
 * Defaults to http://localhost/emule-http-cache-php and the first key in
 * config.php. Needs ext-curl and ext-openssl on the client side; the server
 * itself still needs nothing beyond the PHP defaults.
 *
 * Exit codes: 0 all passed, 1 an assertion failed, 2 the suite could not run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("smoke.php is a CLI tool\n");
}

require __DIR__ . '/bootstrap.php';

foreach (['curl', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, 'the smoke test needs ext-' . $extension . ", which is not loaded\n");
        exit(2);
    }
}

// A part in, a part out, plus the oversized-POST buffer: ~40 MB peak.
TestCase::ensureMemoryLimit(256 * 1024 * 1024);

$appDir = dirname(__DIR__);
$baseUrl = rtrim((string) ($argv[1] ?? 'http://localhost/emule-http-cache-php'), '/');
$apiKey = isset($argv[2]) ? (string) $argv[2] : null;

if ($apiKey === null) {
    try {
        // Config::load() falls back to config.example.php, so a fresh checkout
        // still gets a key rather than dying here.
        $config = Config::load($appDir);
        $keyId = array_key_first($config->apiKeys);
        $apiKey = $keyId === null ? '' : $config->apiKeys[$keyId]->secret;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'could not read an API key from ' . $appDir . '/config.php: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

if ($apiKey === '') {
    fwrite(STDERR, 'could not read an API key from ' . $appDir . "/config.php\n");
    exit(2);
}

try {
    exit((new SmokeTest($baseUrl, $apiKey))->execute());
} catch (\Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(2);
}
