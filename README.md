# eMule HTTP Cache

A tiny, dependency-free chunk cache for eMuleQt's **HTTP Cache** feature.

An uploader that sees two peers wanting the same 9,728,000-byte part encrypts it with a fresh
AES-256-CBC key, `POST`s the ciphertext here once, and hands the URL and key to each peer over the
eD2K link. One upload serves N downloaders. **The server never sees anything useful** — no key, no
IV, no eD2K file hash, no part number, no filename.

The contract is plain HTTP + JSON, so a Go, Rust or S3-presigned backend is a drop-in replacement;
`tests/smoke.php` is the conformance suite for any implementation.

---

## Requirements

- PHP 8.1+, no Composer
- `ext-curl` and `ext-openssl` for tests
- `storage/` and `var/` web-writable
- the app root web-writable for the one `/install` request, or install by hand

## Install

Upload the files, then open **`http://your-host/emule-http-cache-php/install`**.

That page asks for the few settings worth deciding up front — who may upload, daily limits, how long
a chunk lives — writes `config.php`, and then shows you an API key **once**, next to an
`ed2k://` link that configures eMuleQt in one step: copy it, and eMuleQt's clipboard watcher offers
to apply it.

Nothing is written to disk until you submit that form. The key is not shown again: copy it before you
close the tab, and if you lose it, it is still in `config.php` on the server.

The app root has to be writable by the web server for that one request. When it is not, the page says
so and prints the exact `chmod` — tighten it again afterwards, since the installer never writes there
twice.

> **Whoever opens `/install` first gets the key.** A brand-new server has no secret to tell you apart
> from anyone else with the URL, so there is no fixing that in software. Install it as soon as you
> upload it. If you think someone beat you to it, delete `config.php` and do it again.

Until it has been installed, every route answers `503`. The server will *not* fall back to the key in
`config.example.php` — that one is printed in this repository and is therefore not a secret.

Prefer a shell? The old way still works, and a `config.php` you wrote yourself is never read back to
anyone by `/install`:

```sh
cd /path/to/emule-http-cache-php
cp config.example.php config.php                 # then edit the API key
php -r 'echo bin2hex(random_bytes(24)), "\n";'   # generate one
chmod 777 storage var                            # or chown to the web server user
php tests/smoke.php
```

`post_max_size` ≥ 10M.

Apache needs unmatched requests routed to `index.php`; for nginx use `docs/nginx.conf.sample`.

Point eMuleQt at it. Copying the install page's `ed2k://` link does exactly this — the format is
specified in [`docs/ed2k-httpcache-link.md`](docs/ed2k-httpcache-link.md):

```yaml
httpCache:
  enabled: true
  baseUrl: "http://localhost/emule-http-cache-php"
  apiKey: "<the secret from config.php>"
```

Behind Cloudflare or any WAF, the admin may have to allow `eMule*` user agents. Every request eMuleQt
makes — the `/v1/info` probe, the upload, and the chunk download — is `User-Agent: eMuleQt/<version>`,
so one `eMule*` rule covers the client completely; what default bot rules are quick to challenge is an
agent they do not recognise ([User Agent Blocking](https://developers.cloudflare.com/waf/tools/user-agent-blocking/)).

---

## The contract

Base URL is whatever directory the app is deployed into. All error bodies are
`{"error": "<message>", "status": <code>}`.

`/install` is not part of this. It is the PHP server's own setup page; a backend written in something
else reimplements `/v1/*` and nothing more.

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
  "rangeSupported": true,
  "uploadRequiresAuth": true
}
```

`service` and `version` are the handshake: a client that does not see
`"service":"emule-http-cache"` must refuse to use the endpoint.

`uploadRequiresAuth` is `false` on a server that takes uploads without a credential. It is never a
reason for a client to drop the key it has — a key is still what authorises `DELETE`, and the
operator may close the server again tomorrow.

### `POST /v1/chunks`

Stores one encrypted chunk.

| | |
|---|---|
| `Authorization` | `Bearer <apiKey>` — `X-Api-Key: <apiKey>` also accepted. Optional when the server has `openUpload` on |
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

`url` is absolute and is the only field a client may fetch from — never rebuild it from `id`, since
a backend may serve blobs from another host or a signed CDN URL. `Location` carries the same value.

Errors: `400` empty body or length mismatch · `401` bad key, or a missing one on a server that
requires it · `411` no `Content-Length` ·
`413` over `maxChunkSize` · `429` daily quota exhausted · `507` storage failure — both a failed
write and free space that would drop below `minFreeBytes`.

### `GET /v1/chunks/{id}`

No auth. Supports `Range`. `HEAD` behaves identically without a body.

Responds `200` (full) or `206` (ranged) with `Content-Type: application/octet-stream`,
`Accept-Ranges: bytes`, `ETag`, `Cache-Control: public, max-age=<remaining ttl>, immutable` and
`X-Chunk-Expires: <unix>`. Unknown or expired id → `404`. Unsatisfiable range → `416` with
`Content-Range: bytes */<size>`.

Only single ranges are honoured; a multi-range request gets the whole entity, which RFC 9110 §14.2
permits. **Range support is mandatory for a conforming backend**: a downloader that drops mid-chunk
resumes with `Range: bytes=<offset-16>-`, using the preceding ciphertext block as the CBC IV, and
without `206` would have to restart a 9.28 MB transfer.

### `DELETE /v1/chunks/{id}`

`Authorization: Bearer <apiKey>`, and only the uploader's key works. `204` on success. A chunk
belonging to another key reports `404`, not `403`, so a valid key cannot probe the id space.

Errors: `401` bad/missing key · `404` unknown, expired, or already deleted · `500` the chunk could
not be removed and is still on disk. A `204` guarantees the chunk is gone; a backend that cannot
remove it — usually an unwritable storage directory — must say so rather than let the client believe
a still-downloadable chunk was deleted.

eMuleQt does **not** call this automatically: a failed download is as likely to be the downloader's
or the network's fault as the blob's, so entries lapse at their TTL instead. It exists for explicit
cleanup — quota pressure, an operator purge, a client shutting down for good.

---

## API keys

`config.php` holds as many as you like, each with its own daily allowance:

```php
'apiKeys' => [
    'laptop'  => ['secret' => '…', 'quotaBytesPerDay' => 5_368_709_120],
    'seedbox' => ['secret' => '…', 'quotaBytesPerDay' => 0],
    'old-box' => ['secret' => '…', 'enabled' => false],
],
```

The id labels the chunk's owner and its counter in `var/`, so keep it to `[A-Za-z0-9._-]`.

`'enabled' => false` revokes one uploader without deleting the entry: every client and every
`ed2k://` link carrying that secret stops working at once, `DELETE` included, and you still know
whose it was. Deleting the entry does the same and loses the record.

The installer writes `config.php` mode `0644` so the CLI tools can still read it when the web server
owns the file. On a shared host where that is too generous, `chmod 600 config.php` — as long as
`bin/gc.php` and the test suites run as the same user as the web server.

### Letting anyone upload

`'openUpload' => true` accepts `POST /v1/chunks` with no credential at all; the install form's
"anyone can upload" checkbox sets it. Two consequences, neither obvious:

- An anonymous upload is owned by the reserved key id `anonymous`, which nobody can authenticate as.
  Those chunks **cannot be deleted through the API** and only lapse at their TTL. A key named
  `anonymous` in `config.php` is ignored on load, precisely so that ability cannot be handed out.
- A *wrong* key is still a `401`. Only an absent one falls through to anonymous, so a client with a
  mistyped secret finds out, instead of quietly uploading chunks it can never delete.

Set `openUploadQuotaBytesPerDay` — the entire internet shares that one counter — and set
`minFreeBytes` as well. Clients can tell the difference from `uploadRequiresAuth` in `/v1/info`.

## Why the download URL has no auth

The `id` is 128 bits from a CSPRNG and it *is* the capability: guessing one is infeasible, and the
uploader hands it only to peers it chose. Requiring a key on the GET would mean sharing the
uploader's credential with every downloader, which is strictly worse. The real protection is that
the body is AES-256-CBC ciphertext, keyed per chunk and shared only over the eD2K link — this
server's disks and backups hold nothing but opaque blobs of a uniform size.

Consequences a backend operator should know:

- **Do not log request bodies**, and prefer not to log full URLs — a URL is a bearer token.
- Chunks are immutable and short-lived. There is no update verb.
- Uniform 9,728,016-byte blobs are the norm; anything else is a short tail part or an abuse attempt.
- Two settings keep a key from being used as free storage, and they are not interchangeable.
  `quotaBytesPerDay` is fairness: it caps one key for one UTC day, so N keys can still fill the
  volume between them. `minFreeBytes` is host protection: uploads are refused with `507` once free
  space would drop below it, whoever is asking. Set both.

## Storage layout

```
storage/<first 2 hex of id>/<id>.bin     ciphertext
storage/<first 2 hex of id>/<id>.json    {id,size,sha256,ownerKeyId,createdAt,expiresAt}
var/quota-<keyId>-<YYYYMMDD>.txt         bytes charged today
var/gc-last.txt                          unix time the last expiry sweep started
```

Uploads land in `.tmp-<id>` and are `rename()`d into place, so a reader can never observe a partial
chunk. Nothing is buffered whole: ingest reads in 1 MiB slices, delivery `fread`s in 512 KiB slices.

## Expiry

Reads never delete — a `GET` must not do write work. Three triggers reclaim expired chunks, the
first two checked at the top of every authenticated `POST /v1/chunks`, before it is accepted or
refused:

- **a day since the last sweep**, recorded in `var/gc-last.txt` and claimed under a non-blocking
  `flock` so concurrent requests produce one sweep, not many. Checked on *every* upload attempt
  rather than only the successful ones, because a server refusing uploads for want of space must
  still be able to reclaim the space that would let them through;
- **probabilistically**, on the same check (`gcProbability`, default 1%), for a busy install;
- **`php bin/gc.php [maxDeletes]`**, for cron.

The first two have no clock of their own — "daily" means "on the first upload attempt after the day
has lapsed", so an install nobody uploads to sweeps nothing. **Cron is the only real guarantee.**
With cron in place set `'gcProbability' => 0.0`; the daily floor stays on regardless and costs one
small file read per attempt.

```cron
# sudo crontab -u daemon -e    — must be the web server user, see below
17 * * * * /usr/bin/php /path/to/emule-http-cache-php/bin/gc.php >/dev/null 2>&1
```

Hourly suits TTLs measured in days: an expired chunk lingers at most an hour past `expiresAt` and is
unreachable the whole time, so the lag costs disk, never correctness. Minute 17 keeps it clear of
the top-of-hour crowd. Mind the budget — 200 deletes per run by default, so hourly reclaims 4,800 a
day; an install expiring more than that needs a larger argument.

Run it **as the web server user**. `unlink(2)` needs write permission on the *containing* directory
and Apache creates `storage/<shard>/` as its own user, so a sweep from an ordinary shell walks the
whole store, fails every unlink and prints `reclaimed 0` while looking like a clean no-op. A
world-writable `storage/` does not help; the shard directory's mode governs. The command reports
when the previous sweep ran, so a stalled schedule is visible:

```
last sweep: 2026-08-18T04:11:07Z (6.2 h ago)
reclaimed 3 expired item(s)
```

The sweep also reaps `.tmp-*` files older than an hour (interrupted uploads) and quota counters
older than a week.

## Testing

```sh
php tests/smoke.php [baseUrl] [apiKey]
```

Defaults to `http://localhost/emule-http-cache-php` and the first key in `config.php`. Exit code is
0 when everything passed, 1 on a failed assertion, 2 when the suite could not run at all.

31 assertions covering the whole contract: `/v1/info` shape, the auth rejections, a real
9,728,000-byte part encrypted with AES-256-CBC, upload, byte-exact download, decryption back to the
original, four range forms plus `416`, `HEAD`, owner-only delete, and the `404`/`413`/`405` paths.
It speaks nothing but HTTP, so it is equally valid against a non-PHP backend. It reads
`uploadRequiresAuth` from `/v1/info` and asserts the behaviour that server actually promises, so an
open server passes the same 31.

No Apache handy? The built-in server is enough for a full run:

```sh
php -d post_max_size=12M -d memory_limit=256M -S localhost:8080 index.php
php tests/smoke.php http://localhost:8080
```

The `post_max_size` override is not optional. Passing `index.php` as the router script keeps
`storage/` unreachable, exactly as `.htaccess` does under Apache.

### Local tests

```sh
php tests/unit.php
```

The free-space floor, the config fallbacks, the sweep schedule, the installer, and the `ed2k://` link
format — everything a portable HTTP suite has no way to reach. It builds throwaway app directories
under the system temp dir, so it has to run on the same machine as the code.
