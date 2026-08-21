<?php

declare(strict_types=1);

namespace EMule\HttpCache;

use EMule\HttpCache\Http\Request;
use EMule\HttpCache\Http\Response;
use EMule\HttpCache\Http\Router;
use EMule\HttpCache\Install\InstallController;
use EMule\HttpCache\Install\Installer;

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
    $installer = new Installer(__DIR__);

    // Nothing gets served until this server has a config.php of its own.
    // Config::load() would otherwise fall back to config.example.php and run
    // the whole API on the key that is published in the repository.
    if (!$installer->isInstalled()) {
        (new InstallController($installer))->handle(Request::method(), Request::path());
    } else {
        (new Router(Config::load(__DIR__)))->dispatch();
    }
} catch (\Throwable $e) {
    error_log('emule-http-cache: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        Response::error(500, 'internal error');
    }
}
