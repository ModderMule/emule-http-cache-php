# eMule HTTP Cache — reference server

A tiny, dependency-free chunk cache for eMuleQt's **HTTP Cache** feature.

An eMuleQt uploader that notices two or more peers wanting the same 9,728,000-byte part encrypts
that part with a freshly generated AES-256-CBC key, `POST`s the ciphertext here once, and hands the
resulting URL plus the key to every peer over the eD2K link. Each peer then pulls the part over
plain HTTP. One upload serves N downloaders.

**This server never sees anything useful.** It receives ciphertext and nothing else — no key, no IV,
no eD2K file hash, no part number, no filename. It cannot tell which file a chunk belongs to, and it
cannot decrypt it.

This PHP implementation is the *reference*, not the requirement. The contract below is plain
HTTP + JSON; a Go, Rust, nginx+object-store or S3-presigned backend is a drop-in replacement.
`tests/smoke.php` is the conformance suite — point it at any implementation and it must pass
unchanged.

---

## Requirements

- PHP 8.1+ (developed against 8.2), no extensions beyond the defaults, no Composer.
- Apache with `mod_rewrite` and `AllowOverride All` (or at least `FileInfo`), or any server that can
  route all unmatched requests to `index.php`.
- To *run the test suite* (not the server): `ext-curl` and `ext-openssl`, on the machine running
  the test. Both ship enabled with XAMPP and with every mainstream distro and Windows build.
- `post_max_size` **≥ 10M**. A part is 9,728,000 bytes of plaintext → 9,728,016 after PKCS#7
  padding. The default 8M will silently truncate uploads. `.htaccess` deliberately does *not* set
  this, because `php_value` there is a 500 under php-fpm.
- `storage/` and `var/` writable by the web server user (`daemon` under XAMPP on macOS).

## Install

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/emule-http-cache-php
cp config.example.php config.php          # then edit the API key
php -r 'echo bin2hex(random_bytes(24)), "\n";'   # generate one
chmod 777 storage var                     # or chown to the web server user
php tests/smoke.php
```

Point eMuleQt at it:

```yaml
httpCache:
  enabled: true
  baseUrl: "http://localhost/emule-http-cache-php"
  apiKey: "<the secret from config.php>"
```

---

## The contract

Base URL below is whatever directory the app is deployed into, e.g.
`http://localhost/emule-http-cache-php`. All error bodies are
`{"error": "<message>", "status": <code>}`.

### `GET /v1/info`

No auth. Lets a client discover limits before it wastes an upload.

```json
{
  "service": "emule-http-cache",
  "version": 1,
  "implementation": "php",
  "maxChunkSize": 10485760,
  "defaultTtl": 172800,
  "maxTtl": 604800,
  "rangeSupported": true
}
```

`service` and `version` are the handshake: a client that does not see
`"service":"emule-http-cache"` must refuse to use the endpoint.

### `POST /v1/chunks`

Stores one encrypted chunk.

| | |
|---|---|
| `Authorization` | `Bearer <apiKey>` — `X-Api-Key: <apiKey>` also accepted |
| `Content-Type` | `application/octet-stream` |
| `Content-Length` | **required** — chunked uploads are rejected with `411` |
| `X-Chunk-TTL` | optional, seconds; clamped to `maxTtl` |
| body | raw ciphertext |

**201**

```json
{
  "id": "87d7f7573b0263fc9faf9ed65cb62841",
  "url": "http://localhost/emule-http-cache-php/v1/chunks/87d7f7573b0263fc9faf9ed65cb62841",
  "size": 9728016,
  "sha256": "…",
  "expires": 1755500000
}
```

`url` is absolute and is the only field a client must use to fetch — never reconstruct it from `id`,
because a backend is free to serve blobs from a different host or a signed CDN URL. A `Location`
header carries the same value.

Errors: `400` empty body or length mismatch · `401` bad/missing key · `411` no `Content-Length` ·
`413` over `maxChunkSize` · `429` daily quota exhausted · `507` storage failure, which covers
both a write that failed and free space that would drop below `minFreeBytes`.

### `GET /v1/chunks/{id}`

No auth. Supports `Range`. `HEAD` behaves identically without a body.

Responds `200` (full) or `206` (ranged) with `Content-Type: application/octet-stream`,
`Accept-Ranges: bytes`, `ETag`, `Cache-Control: public, max-age=<remaining ttl>, immutable` and
`X-Chunk-Expires: <unix>`. Unknown or expired id → `404`. Unsatisfiable range → `416` with
`Content-Range: bytes */<size>`.

Only single ranges are honoured; a multi-range request gets the whole entity, which RFC 9110 §14.2
permits. **Range support is mandatory for a conforming backend**: an eMuleQt downloader that drops
mid-chunk resumes with `Range: bytes=<offset-16>-`, using the preceding ciphertext block as the CBC
IV. Without `206` it would have to restart a 9.28 MB transfer.

### `DELETE /v1/chunks/{id}`

`Authorization: Bearer <apiKey>`, and only the uploader's key works. `204` on success. A chunk
belonging to another key reports `404`, not `403`, so a valid key cannot be used to probe the id
space.

Errors: `401` bad/missing key · `404` unknown, expired, or already deleted · `500` the chunk could
not be removed and is still on disk. A `204` is a guarantee that the chunk is gone; a backend that
cannot remove it — an unwritable storage directory is the usual cause — must say so rather than let
the client believe a still-downloadable chunk was deleted.

eMuleQt does **not** call this automatically — a failed download is as likely to be the downloader's
or the network's fault as the blob's, so entries are left to lapse at their TTL. It exists for
explicit cleanup: quota pressure, an operator purge, a client shutting down for good.

---

## Why the download URL has no auth

The `id` is 128 bits from a CSPRNG, and it *is* the capability. Guessing one is infeasible; the
uploader hands it only to peers it chose. Putting a key on the GET would mean every downloader needs
the uploader's credential, which is strictly worse.

What actually protects the content is that the body is AES-256-CBC ciphertext with a random key and
IV generated per chunk by the uploader and transmitted only over the eD2K link. Compromising this
server, its disks, or its backups yields nothing but opaque blobs of a uniform size.

Consequences a backend operator should know:

- **Do not log request bodies**, and prefer not to log full URLs — a URL is a bearer token.
- Chunks are immutable and short-lived. There is no update verb.
- Uniform 9,728,016-byte blobs are the norm; anything else is a short tail part or an abuse attempt.
- Two settings keep a key from being used as free storage, and they are not interchangeable.
  `quotaBytesPerDay` is fairness: it caps one key for one UTC day, so N keys can still fill the
  volume between them. `minFreeBytes` is host protection: uploads are refused with `507` once free
  space on the storage volume would drop below it, whoever is asking. Set both.

## Storage layout

```
storage/<first 2 hex of id>/<id>.bin     ciphertext
storage/<first 2 hex of id>/<id>.json    {id,size,sha256,ownerKeyId,createdAt,expiresAt}
var/quota-<keyId>-<YYYYMMDD>.txt         bytes charged today
var/gc-last.txt                          unix time the last expiry sweep started
```

Uploads land in `.tmp-<id>` and are `rename()`d into place, so a reader can never observe a partial
chunk. Nothing is buffered whole: ingest reads `php://input` in 1 MiB slices, delivery `fread`s in
512 KiB slices.

## Expiry

Reads never delete — a `GET` must not do write work. Three triggers reclaim expired chunks, the
first two checked at the top of every authenticated `POST /v1/chunks`, before the request is
accepted or refused:

- **a day since the last sweep**, recorded in `var/gc-last.txt`. This is the floor, and the reason
  it is checked on *every* upload attempt rather than only successful ones: a server refusing
  uploads because it is out of space must still be able to reclaim the space that would let them
  through. Claimed under a non-blocking `flock`, so concurrent requests produce one sweep, not many;
- **probabilistically**, on the same check (`gcProbability`, default 1%), which keeps a busy install
  tidy in between;
- **`php bin/gc.php [maxDeletes]`**, for cron.

The first two are request-driven and have no clock of their own — "daily" means "on the first upload
attempt after the day has lapsed", so an install nobody uploads to sweeps nothing. **Cron is the
only real guarantee.** With cron in place set `'gcProbability' => 0.0` so uploads stop paying for
cleanup; the daily floor stays on regardless and costs one small file read per attempt.

Run `bin/gc.php` **as the web server user** — `sudo -u daemon php bin/gc.php` under XAMPP. `unlink(2)`
needs write permission on the *containing* directory, and Apache creates `storage/<shard>/` as its
own user, so a sweep from an ordinary shell walks the whole store, fails every unlink and prints
`reclaimed 0` while looking like a clean no-op. `storage/` being world-writable does not help; the
shard directory's mode is what governs. The command reports when the previous sweep ran, so a
stalled schedule is visible:

```
last sweep: 2026-08-18T04:11:07Z (6.2 h ago)
reclaimed 3 expired item(s)
```

```cron
# sudo crontab -u daemon -e      — the web server user, per the warning above
17 * * * * /Applications/XAMPP/xamppfiles/bin/php \
    /Applications/XAMPP/xamppfiles/htdocs/emule-http-cache-php/bin/gc.php >/dev/null 2>&1
```

Hourly is the right cadence for TTLs measured in days. An expired chunk lingers at most an hour past
its `expiresAt`, and it is unreachable from the moment it expires — `Store::meta()` reports it absent
and `GET` answers `404` — so the lag costs disk, never correctness. Minute 17 rather than 0 keeps the
sweep out of the top-of-hour crowd. Mind the budget: `bin/gc.php` removes at most 200 items per run
by default, so hourly reclaims 4,800 a day; an install that expires more than that builds a backlog
and wants a larger argument (`… /bin/gc.php 1000`).

The sweep also reaps `.tmp-*` files older than an hour (interrupted uploads) and quota counters
older than a week.

## Testing

```sh
php tests/smoke.php [baseUrl] [apiKey]
```

Defaults to `http://localhost/emule-http-cache-php` and the first key in `config.php`. Exit code
is 0 when everything passed, 1 on a failed assertion, 2 when the suite could not run at all.

31 assertions covering the whole contract: `/v1/info` shape, the three auth rejections, a real
9,728,000-byte part encrypted with AES-256-CBC, upload, byte-exact download, decryption back to
the original, four range forms plus `416`, `HEAD`, owner-only delete, and the `404`/`413`/`405`
paths. It speaks nothing but HTTP, so it is equally valid against a non-PHP backend — and it runs
anywhere PHP does, Windows included.

No Apache handy? The built-in server is enough for a full run:

```sh
php -d post_max_size=12M -d memory_limit=256M -S localhost:8080 index.php
php tests/smoke.php http://localhost:8080
```

The `post_max_size` override is not optional — a chunk is 9,728,016 bytes and PHP's 8M default
silently discards a body that size. Passing `index.php` as the router script keeps `storage/`
unreachable, exactly as `.htaccess` does under Apache.

### Local tests

```sh
php tests/unit.php
```

16 assertions over the things a portable HTTP suite cannot reach, because they are driven by
server-side config a client has no way to set: the `minFreeBytes` floor, the `Config::load()`
fallbacks, and the daily sweep trigger. It builds throwaway app directories under the system temp
dir, so it has to run on the machine hosting the code and is **not** part of the contract a non-PHP
backend must satisfy. `smoke.php` remains the conformance suite.

## Files

| Path | Role |
|---|---|
| `index.php` | front controller |
| `src/Router.php` | the routing table — the whole API surface |
| `src/Config.php` | typed loader for `config.php` |
| `src/Auth.php` | constant-time API-key check |
| `src/Store.php` | streaming blob store, atomic commits |
| `src/StorageArea.php` | shared shard walking, sidecar reads and reaping |
| `src/RangeResponse.php` | RFC 9110 §14 byte-range serving |
| `src/ByteRange.php` | `Range` header parsing |
| `src/Quota.php` | per-key, per-UTC-day allowance, `flock`-guarded |
| `src/Gc.php` | bounded expiry sweep |
| `src/Response.php` | JSON/status helpers |
| `src/ChunkMeta.php` | one chunk's sidecar |
| `src/IngestResult.php` | outcome of an upload |
| `src/ApiKey.php` | one configured credential |
| `src/Autoloader.php` | the Composer replacement |
| `bin/gc.php` | CLI sweep for cron |
| `tests/smoke.php` | executable specification — run this |
| `tests/unit.php` | local-only suite, for what HTTP cannot reach |
| `tests/StorageTest.php` | free-space floor, config fallbacks, sweep schedule |
| `tests/SmokeTest.php` | the 31 assertions |
| `tests/TestCase.php` | assertion harness, inherited by future tests |
| `tests/HttpClient.php` | ext-curl wrapper with exact header control |
