<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

use EMule\HttpCache\Config;
use EMule\HttpCache\Gc;
use EMule\HttpCache\Store;

/**
 * Local-only tests for the parts of the storage layer HTTP cannot reach.
 *
 * The free-space floor and the daily sweep trigger are both driven by server
 * configuration, so smoke.php — which has to pass against any backend it is
 * pointed at, over nothing but HTTP — has no way to exercise them. These build
 * throwaway app directories under the system temp dir instead, and so must run
 * on the same machine as the code.
 */
class StorageTest extends TestCase
{
    protected string $root = '';

    public function run(): void
    {
        printf("\n== eMule HTTP Cache unit tests ==\n");

        $this->root = $this->makeTempDir();

        try {
            $this->checkFreeSpaceFloor();
            $this->checkConfigFallbacks();
            $this->checkSweepSchedule();
        } finally {
            $this->removeTree($this->root);
        }
    }

    // -- sections -------------------------------------------------------------

    protected function checkFreeSpaceFloor(): void
    {
        $this->section('Store::hasRoomFor');

        $store = new Store($this->config('floor-off', ['minFreeBytes' => 0]));
        $this->assert($store->hasRoomFor(1 << 40), 'a zero floor has room for anything');

        $config = $this->config('floor-on', ['minFreeBytes' => 1024]);
        $free = (int) disk_free_space($config->storageDir);
        $store = new Store($config);
        $this->assert($store->hasRoomFor(0), 'a floor far below free space leaves room');

        $store = new Store($this->config('floor-high', ['minFreeBytes' => $free + 1_000_000_000]));
        $this->assert(!$store->hasRoomFor(0), 'a floor above free space leaves none');

        // One MiB of headroom, and a body that asks for two.
        $store = new Store($this->config('floor-edge', ['minFreeBytes' => $free - 1_048_576]));
        $this->assert($store->hasRoomFor(0), 'an empty body fits inside the headroom');
        $this->assert(!$store->hasRoomFor(2_097_152), 'the declared length counts against the floor');

        $store = new Store($this->config('floor-nodir', [
            'minFreeBytes' => $free + 1_000_000_000,
            'storageDir' => 'no-such-directory',
        ]));
        $this->assert($store->hasRoomFor(0), 'an unreadable storage path fails open');
    }

    protected function checkConfigFallbacks(): void
    {
        $this->section('Config::load fallbacks');

        // A config written before these keys existed must still load sanely.
        $config = $this->config('fallbacks');
        $this->check('an absent minFreeBytes means no floor', $config->minFreeBytes, 0);
        $this->check('an absent defaultTtl is 48 hours', $config->defaultTtl, 172_800);
        $this->check('an absent maxTtl is one week', $config->maxTtl, 604_800);

        $config = $this->config('negative-floor', ['minFreeBytes' => -5]);
        $this->check('a negative floor clamps to zero', $config->minFreeBytes, 0);
    }

    protected function checkSweepSchedule(): void
    {
        $this->section('Gc daily trigger');

        // gcProbability 0.0 removes the dice, so anything that sweeps here did
        // so because the day had lapsed.
        $config = $this->config('sweep', ['gcProbability' => 0.0]);
        $store = new Store($config);
        $gc = new Gc($config, $store);
        $stamp = $config->varDir . '/gc-last.txt';

        $this->assert($gc->lastSweepAt() === null, 'no stamp before the first sweep');

        $gc->sweep();
        $this->assert($gc->lastSweepAt() !== null, 'sweep() records when it ran');

        $expired = $this->writeExpiredChunk($store, time() - 60);
        $this->assert(is_file($store->blobPath($expired)), 'an expired chunk is on disk');

        file_put_contents($stamp, (string) (time() - 120));
        $gc->maybeSweep();
        $this->assert(
            is_file($store->blobPath($expired)),
            'a fresh stamp holds the sweep off',
            'the chunk was reclaimed early',
        );

        file_put_contents($stamp, (string) (time() - 86_500));
        $gc->maybeSweep();
        $this->assert(
            !is_file($store->blobPath($expired)),
            'a lapsed day sweeps without the dice',
            'the expired chunk survived',
        );
        $this->assert((time() - (int) $gc->lastSweepAt()) < 60, 'the stamp is refreshed');
    }

    // -- fixtures ---------------------------------------------------------------

    /**
     * A Config over a fresh throwaway app dir. Each gets its own path so no two
     * ever collide in the opcode cache.
     *
     * @param array<string, mixed> $overrides written into the generated config.php
     */
    protected function config(string $name, array $overrides = []): Config
    {
        $dir = $this->root . '/' . $name;
        foreach ([$dir, $dir . '/storage', $dir . '/var'] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0o777, true) && !is_dir($path)) {
                throw new \RuntimeException('could not create ' . $path);
            }
        }

        $body = "<?php\n\nreturn [\n    'apiKeys' => ['t' => ['secret' => 'test-secret']],\n";
        foreach ($overrides as $key => $value) {
            $body .= sprintf("    %s => %s,\n", var_export($key, true), var_export($value, true));
        }
        $body .= "];\n";

        if (@file_put_contents($dir . '/config.php', $body) === false) {
            throw new \RuntimeException('could not write ' . $dir . '/config.php');
        }

        return Config::load($dir);
    }

    /** A blob plus sidecar that expired at $expiresAt. Returns its id. */
    protected function writeExpiredChunk(Store $store, int $expiresAt): string
    {
        $id = $store->newId();
        $shard = dirname($store->blobPath($id));

        if (!is_dir($shard) && !@mkdir($shard, 0o777, true) && !is_dir($shard)) {
            throw new \RuntimeException('could not create ' . $shard);
        }

        $body = 'ciphertext';
        file_put_contents($store->blobPath($id), $body);
        file_put_contents($store->metaPath($id), json_encode([
            'id' => $id,
            'size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'ownerKeyId' => 't',
            'createdAt' => $expiresAt - 3600,
            'expiresAt' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        return $id;
    }

    protected function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/emule-http-cache-test-' . bin2hex(random_bytes(8));

        if (!@mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new \RuntimeException('could not create a temp directory at ' . $path);
        }

        return $path;
    }

    /** Recursive rmdir, scoped to the temp tree this suite created. */
    protected function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeTree($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
