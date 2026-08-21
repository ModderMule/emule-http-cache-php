<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * CLI expiry sweep.
 *
 *   php bin/gc.php [maxDeletes]
 *
 * Suitable for cron. Hourly suits TTLs measured in days, and an off-the-hour
 * minute keeps it clear of everything else scheduled at :00:
 *   17 * * * * /Applications/XAMPP/xamppfiles/bin/php \
 *       /Applications/XAMPP/xamppfiles/htdocs/emule-http-cache-php/bin/gc.php >/dev/null 2>&1
 *
 * That reclaims up to 200 items an hour; raise maxDeletes on an install that
 * expires more than 4,800 chunks a day, or the backlog only grows.
 *
 * With cron in place, set 'gcProbability' => 0.0 in config.php so uploads stop
 * paying for cleanup. The server's own daily trigger stays on regardless, but it
 * only fires when an upload arrives — this is the one that runs on a schedule.
 *
 * Run it as the web server user (sudo -u daemon under XAMPP), or every unlink
 * fails on shard directories Apache owns and the reclaim count is a silent zero.
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
$gc = new Gc($config, $store);

// Read before sweeping: sweep() stamps the new time on its way in.
$last = $gc->lastSweepAt();
$deleted = $gc->sweep($maxDeletes);

printf("last sweep: %s\n", $last === null
    ? 'never'
    : sprintf('%s (%.1f h ago)', gmdate('Y-m-d\TH:i:s\Z', $last), (time() - $last) / 3600));
printf("reclaimed %d expired item(s)\n", $deleted);
