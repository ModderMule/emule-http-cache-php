<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

use EMule\HttpCache\Config;
use EMule\HttpCache\Install\InstallController;
use EMule\HttpCache\Install\Installer;
use EMule\HttpCache\Security\Auth;
use EMule\HttpCache\Storage\Gc;
use EMule\HttpCache\Storage\Quota;
use EMule\HttpCache\Storage\Store;

/**
 * Front controller.
 *
 * The routing table is the whole API surface — see README.md for the contract a
 * non-PHP backend has to reproduce. /install is the exception: it is this
 * implementation's own setup page and no other backend needs it.
 *
 *   GET    /                      status page
 *   GET    /install               setup page, once installed only says so
 *   GET    /v1/info               server limits (no auth)
 *   POST   /v1/chunks             store a chunk (auth, unless openUpload)
 *   GET    /v1/chunks/{id}        fetch a chunk, Range-capable (no auth)
 *   HEAD   /v1/chunks/{id}        as GET, headers only (no auth)
 *   DELETE /v1/chunks/{id}        drop a chunk (auth, owner only)
 */
class Router
{
    protected readonly Store $store;
    protected readonly Quota $quota;
    protected readonly Gc $gc;

    public function __construct(protected readonly Config $config)
    {
        $this->store = new Store($config);
        $this->quota = new Quota($config);
        $this->gc = new Gc($config, $this->store);
    }

    public function dispatch(): void
    {
        $method = Request::method();
        $path = Request::path();

        if ($path === '' || $path === '/') {
            $this->handleRoot($method);

            return;
        }

        if ($path === '/install') {
            (new InstallController(new Installer($this->config->baseDir)))->handle($method, $path);

            return;
        }

        if ($path === '/v1/info') {
            $this->requireMethod($method, ['GET', 'HEAD']) && $this->handleInfo();

            return;
        }

        if ($path === '/v1/chunks') {
            $this->requireMethod($method, ['POST']) && $this->handleUpload();

            return;
        }

        if (preg_match('#^/v1/chunks/([0-9a-f]{32})$#u', $path, $m) === 1) {
            $id = $m[1];
            match (true) {
                $method === 'GET' || $method === 'HEAD' => $this->handleDownload($id),
                $method === 'DELETE' => $this->handleDelete($id),
                default => $this->methodNotAllowed(['GET', 'HEAD', 'DELETE']),
            };

            return;
        }

        // A well-formed path with a malformed id is still a 404, not a 400: the id
        // space is opaque to clients and must not leak validation detail.
        $this->notFound();
    }

    // -- routes -------------------------------------------------------------

    protected function handleRoot(string $method): void
    {
        if (!$this->requireMethod($method, ['GET', 'HEAD'])) {
            return;
        }

        if ($method === 'HEAD') {
            HtmlPage::headers(200);

            return;
        }

        $safeBase = HtmlPage::escape($this->publicBaseUrl());
        $auth = $this->config->openUpload
            ? '<p>Uploads are open: no API key is needed to store a chunk here.</p>'
            : '';

        HtmlPage::send(200, 'Status', 'status', ['safeBase' => $safeBase, 'auth' => $auth]);
    }

    protected function handleInfo(): void
    {
        Response::json(200, [
            'service' => 'emule-http-cache',
            'version' => 1,
            'implementation' => 'php',
            'maxChunkSize' => $this->config->maxChunkSize,
            'defaultTtl' => $this->config->defaultTtl,
            'maxTtl' => $this->config->maxTtl,
            'rangeSupported' => true,
            // Saves a client discovering the answer by eating a 401. It never
            // means "drop the key": a key is still what authorises DELETE.
            'uploadRequiresAuth' => !$this->config->openUpload,
        ]);
    }

    protected function handleUpload(): void
    {
        $keyId = $this->uploaderKeyId();
        if ($keyId === null) {
            return;
        }

        // Every authenticated attempt, not just the ones that succeed: a server
        // refusing uploads for want of space must still reclaim, and whatever
        // this frees counts toward the room check below.
        $this->gc->maybeSweep();

        $declared = $this->contentLength();
        if ($declared === null) {
            Response::error(411, 'Content-Length required');

            return;
        }

        if ($declared > $this->config->maxChunkSize) {
            Response::error(413, 'chunk exceeds maxChunkSize');

            return;
        }

        // Refuse before the volume fills rather than after. Measured against the
        // declared length, so the floor holds for the largest body this request
        // could deliver, and checked before the quota so a refusal costs the key
        // nothing.
        if (!$this->store->hasRoomFor($declared)) {
            Response::error(507, 'insufficient storage');

            return;
        }

        // Reserve before reading the body so a flood of concurrent POSTs cannot
        // collectively overshoot the daily allowance.
        if (!$this->quota->consume($keyId, $declared)) {
            Response::error(429, 'daily upload quota exhausted');

            return;
        }

        $ttl = $this->config->clampTtl($this->requestedTtl());
        $result = $this->store->ingestRequestBody($keyId, $ttl, $declared);

        if (!$result->ok || $result->meta === null) {
            $this->quota->refund($keyId, $declared);
            Response::error($result->status, $result->error);

            return;
        }

        $meta = $result->meta;
        $url = $this->publicBaseUrl() . '/v1/chunks/' . $meta->id;

        header('Location: ' . $url);
        Response::json(201, [
            'id' => $meta->id,
            'url' => $url,
            'size' => $meta->size,
            'sha256' => $meta->sha256,
            'expires' => $meta->expiresAt,
        ]);
    }

    protected function handleDownload(string $id): void
    {
        $meta = $this->store->meta($id);
        if ($meta === null) {
            $this->notFound();

            return;
        }

        RangeResponse::serve($this->store, $meta);
    }

    protected function handleDelete(string $id): void
    {
        $keyId = $this->requireKey();
        if ($keyId === null) {
            return;
        }

        $meta = $this->store->meta($id);

        // Only the uploader may drop a chunk. Report a foreign chunk as absent
        // rather than forbidden, so a valid key cannot be used to probe for ids.
        // Nobody can authenticate as "anonymous", so an open server's chunks are
        // undeletable by design and lapse at their TTL instead.
        if ($meta === null || !hash_equals($meta->ownerKeyId, $keyId)) {
            $this->notFound();

            return;
        }

        // A delete that did not happen must not be reported as done. An
        // unwritable shard directory would otherwise leave the client believing
        // the chunk is gone while it stays downloadable until its TTL lapses.
        // A concurrent DELETE that got there first is still a success — the
        // caller asked for the chunk to be gone, and it is.
        if (!$this->store->delete($id) && $this->store->exists($id)) {
            Response::error(500, 'chunk could not be removed');

            return;
        }

        Response::noContent();
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Who to charge and record this upload against, or null after a 401.
     *
     * A credential that was offered and rejected is a 401 even on an open
     * server: downgrading a mistyped key to anonymous would hand the client a
     * chunk it can never delete, and hide the typo for good.
     */
    protected function uploaderKeyId(): ?string
    {
        $keyId = Auth::identify($this->config);
        if ($keyId !== null) {
            return $keyId;
        }

        if ($this->config->openUpload && !Auth::hasCredential()) {
            return Config::ANONYMOUS_KEY_ID;
        }

        return $this->requireKey();
    }

    /** The caller's key id, or null after a 401 has already been sent. */
    protected function requireKey(): ?string
    {
        $keyId = Auth::identify($this->config);
        if ($keyId !== null) {
            return $keyId;
        }

        header('WWW-Authenticate: Bearer realm="emule-http-cache"');
        Response::error(401, 'invalid or missing API key');

        return null;
    }

    protected function notFound(): void
    {
        Response::error(404, 'not found');
    }

    /**
     * Absolute base URL clients should use.
     *
     * Derived from the request unless config.php pins publicBaseUrl — which it
     * must when the app sits behind a reverse proxy that rewrites Host.
     */
    protected function publicBaseUrl(): string
    {
        return $this->config->publicBaseUrl ?? Request::baseUrl();
    }

    protected function contentLength(): ?int
    {
        $raw = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    protected function requestedTtl(): ?int
    {
        $raw = $_SERVER['HTTP_X_CHUNK_TTL'] ?? null;

        return (is_string($raw) && ctype_digit(trim($raw))) ? (int) trim($raw) : null;
    }

    /** @param list<string> $allowed */
    protected function requireMethod(string $method, array $allowed): bool
    {
        if (in_array($method, $allowed, true)) {
            return true;
        }

        $this->methodNotAllowed($allowed);

        return false;
    }

    /** @param list<string> $allowed */
    protected function methodNotAllowed(array $allowed): void
    {
        header('Allow: ' . implode(', ', $allowed));
        Response::error(405, 'method not allowed');
    }
}
