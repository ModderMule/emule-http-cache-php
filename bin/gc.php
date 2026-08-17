<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * CLI expiry sweep.
 *
 *   php bin/gc.php [maxDeletes]
 *
 * Suitable for cron, e.g. every 15 minutes:
 *   *\/15 * * * * /Applications/XAMPP/xamppfiles/bin/php \
 *       /Applications/XAMPP/xamppfiles/htdocs/emule-http-cache-php/bin/gc.php >/dev/null 2>&1
 *
 * With cron in place, set 'gcProbability' => 0.0 in config.php so uploads stop
 * paying for cleanup.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("gc.php is a CLI tool\n");
}

require __DIR__ . '/../src/bootstrap.php';

$baseDir = dirname(__DIR__);
$maxDeletes = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 200;

$config = Config::load($baseDir);
$store = new Store($config);
$deleted = (new Gc($config, $store))->sweep($maxDeletes);

printf("reclaimed %d expired item(s)\n", $deleted);
