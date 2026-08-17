<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * eMule HTTP Cache — front controller.
 *
 * Serves the chunk-cache REST API documented in README.md. Every request that
 * is not a real file on disk lands here via .htaccess.
 */

require __DIR__ . '/src/bootstrap.php';

// The API is machine-facing: a stray warning in the body would corrupt a JSON
// response or, worse, a chunk. Log instead of print, always.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Chunks are up to ~10 MB and are streamed in slices; output buffering would
// undo that and hold the whole body in memory.
while (ob_get_level() > 0) {
    ob_end_flush();
}

try {
    $config = Config::load(__DIR__);
    (new Router($config))->dispatch();
} catch (\Throwable $e) {
    error_log('emule-http-cache: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        Response::error(500, 'internal error');
    }
}
