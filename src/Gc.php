<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * Expiry sweep.
 *
 * Chunks are never deleted on read (a GET must not do write work), so something
 * has to reclaim them. Two triggers, both optional:
 *
 *  - probabilistically after a successful POST (Config::$gcProbability), which
 *    keeps a casual install tidy with no cron at all;
 *  - bin/gc.php from cron, for installs with real traffic.
 *
 * The sweep is bounded per run so a POST never turns into a long request.
 */
class Gc extends StorageArea
{
    /** An interrupted ingest leaves a .tmp-* behind; reap them after an hour. */
    protected const TEMP_MAX_AGE = 3600;

    /** Quota counters are per UTC day; anything older than a week is dead weight. */
    protected const QUOTA_MAX_AGE = 7 * 86_400;

    public function __construct(Config $config, protected readonly Store $store)
    {
        parent::__construct($config);
    }

    /** Number of items reclaimed. */
    public function sweep(int $maxDeletes = 200): int
    {
        $now = time();
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

    /** Run the sweep with probability Config::$gcProbability. */
    public function maybeSweep(): void
    {
        if ($this->config->gcProbability <= 0.0) {
            return;
        }

        if (random_int(1, 10_000) <= (int) round($this->config->gcProbability * 10_000)) {
            $this->sweep(50);
        }
    }

    // -- internals ------------------------------------------------------------

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
