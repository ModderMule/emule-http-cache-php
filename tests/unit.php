<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

/**
 * eMule HTTP Cache — local test suite.
 *
 *   php tests/unit.php
 *
 * Covers what smoke.php cannot: behaviour that depends on server-side config,
 * which a portable HTTP conformance test has no way to set. Unlike smoke.php
 * this has to run on the same machine as the code, so it is not part of the
 * contract a non-PHP backend must satisfy.
 *
 * Exit codes: 0 all passed, 1 an assertion failed, 2 the suite could not run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("unit.php is a CLI tool\n");
}

require __DIR__ . '/bootstrap.php';

try {
    exit((new StorageTest())->execute());
} catch (\Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(2);
}
