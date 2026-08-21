<?php

declare(strict_types=1);

namespace EMule\HttpCache\Storage;

use EMule\HttpCache\Config;

/**
 * Expiry sweep.
 *
 * Chunks are never deleted on read (a GET must not do write work), so something
 * has to reclaim them. Three triggers:
 *
 *  - a day since the last recorded sweep, checked on every authenticated upload
 *    attempt, so a server that has started refusing uploads still cleans up;
 *  - probabilistically on that same check (Config::$gcProbability), which keeps
 *    a busy install tidy between the daily runs;
 *  - bin/gc.php from cron, for installs with real traffic.
 *
 * The first two are request-driven and cannot fire without traffic: "daily"
 * means "on the first upload attempt after the day has lapsed". Cron remains
 * the only real guarantee. var/gc-last.txt records when a sweep last started.
 *
 * The sweep is bounded per run so a POST never turns into a long request.
 */
class Gc extends StorageArea
{
    /** An interrupted ingest leaves a .tmp-* behind; reap them after an hour. */
    protected const TEMP_MAX_AGE = 3600;

    /** Quota counters are per UTC day; anything older than a week is dead weight. */
    protected const QUOTA_MAX_AGE = 7 * 86_400;

    /** Longest a live install may go without a sweep. */
    protected const SWEEP_INTERVAL = 86_400;

    public function __construct(Config $config, protected readonly Store $store)
    {
        parent::__construct($config);
    }

    /** Number of items reclaimed. */
    public function sweep(int $maxDeletes = 200): int
    {
        $now = time();

        // Stamped before the work, not after: a slow sweep must not leave the
        // next request thinking one is still overdue and starting a second.
        $this->recordSweep($now);

        $deleted = 0;

        foreach ($this->store->allIds() as $id) {
            if ($deleted >= $maxDeletes) {
                break;
            }

            $expiresAt = $this->store->rawExpiresAt($id);
            if ($expiresAt !== null && $expiresAt > $now) {
                continue;
            }

            if ($this->store->delete($id)) {
                ++$deleted;
            }
        }

        $deleted += $this->sweepStaleTempFiles($now);
        $deleted += $this->sweepStaleQuotaFiles($now);

        return $deleted;
    }

    /** Run the sweep when a day has lapsed, else with probability Config::$gcProbability. */
    public function maybeSweep(): void
    {
        // The day takes precedence over the dice. An install whose uploads are
        // all being refused would otherwise never reclaim the space that would
        // let them succeed again.
        if ($this->claimOverdueSweep(time())) {
            $this->sweep(50);

            return;
        }

        if ($this->config->gcProbability <= 0.0) {
            return;
        }

        if (random_int(1, 10_000) <= (int) round($this->config->gcProbability * 10_000)) {
            $this->sweep(50);
        }
    }

    /** Unix time the last sweep started, or null when none was ever recorded. */
    public function lastSweepAt(): ?int
    {
        $raw = @file_get_contents($this->stampPath());
        if ($raw === false) {
            return null;
        }

        $last = (int) trim($raw);

        return $last > 0 ? $last : null;
    }

    // -- internals ------------------------------------------------------------

    /**
     * Take the daily sweep slot when it is due and nobody else holds it.
     *
     * Read and write happen under one non-blocking exclusive lock, so of two
     * concurrent requests exactly one claims the slot; the other carries on
     * rather than queueing behind a sweep it does not need.
     *
     * False whenever the stamp could not be written — never sweep on a trigger
     * you failed to record, or an unwritable var/ becomes a sweep per request.
     */
    protected function claimOverdueSweep(int $now): bool
    {
        $path = $this->stampPath();
        if (!$this->ensureVarDir()) {
            return false;
        }

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }

        $last = (int) trim((string) stream_get_contents($fh));
        $due = $last <= 0 || ($now - $last) >= self::SWEEP_INTERVAL;

        if ($due) {
            ftruncate($fh, 0);
            rewind($fh);
            $due = fwrite($fh, (string) $now) !== false;
            fflush($fh);
        }

        flock($fh, LOCK_UN);
        fclose($fh);

        if ($due) {
            $this->shareStamp($path);
        }

        return $due;
    }

    /** Record that a sweep has just started. Best effort — never breaks a request. */
    protected function recordSweep(int $now): bool
    {
        $path = $this->stampPath();

        if (!$this->ensureVarDir() || @file_put_contents($path, (string) $now) === false) {
            return false;
        }

        $this->shareStamp($path);

        return true;
    }

    /**
     * Apache sweeps as the web server user and cron as the shell user. Whoever
     * creates the stamp first must not lock the other out of rewriting it.
     */
    protected function shareStamp(string $path): void
    {
        @chmod($path, 0o666);
    }

    protected function ensureVarDir(): bool
    {
        $dir = $this->config->varDir;

        return is_dir($dir) || @mkdir($dir, 0o775, true) || is_dir($dir);
    }

    protected function stampPath(): string
    {
        return $this->config->varDir . '/gc-last.txt';
    }

    protected function sweepStaleTempFiles(int $now): int
    {
        $removed = 0;

        foreach ($this->shards() as $shard) {
            $removed += $this->reapByPrefix($this->shardPath($shard), '.tmp-', self::TEMP_MAX_AGE, $now);
        }

        return $removed;
    }

    protected function sweepStaleQuotaFiles(int $now): int
    {
        return $this->reapByPrefix($this->config->varDir, 'quota-', self::QUOTA_MAX_AGE, $now);
    }
}
