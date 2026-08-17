<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * Front controller.
 *
 * The routing table is the whole API surface — see README.md for the contract a
 * non-PHP backend has to reproduce.
 *
 *   GET    /                      status page
 *   GET    /v1/info               server limits (no auth)
 *   POST   /v1/chunks             store a chunk (auth)
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
        $method = mb_strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = $this->requestPath();

        if ($path === '' || $path === '/') {
            $this->handleRoot($method);

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

        Response::status(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        if ($method === 'HEAD') {
            return;
        }

        $base = htmlspecialchars($this->publicBaseUrl(), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <!doctype html>
        <meta charset="utf-8">
        <title>eMule HTTP Cache</title>
        <style>
          body{font:14px/1.6 -apple-system,system-ui,sans-serif;max-width:46rem;margin:3rem auto;padding:0 1rem}
          code{background:#f4f4f5;padding:.1rem .35rem;border-radius:3px}
          td{padding:.15rem .75rem .15rem 0;vertical-align:top}
        </style>
        <h1>eMule HTTP Cache</h1>
        <p>Encrypted chunk cache for eMuleQt. This server stores AES-256-CBC ciphertext
        and never receives a key, a file hash or a part number.</p>
        <table>
          <tr><td><code>GET</code></td><td><a href="{$base}/v1/info">{$base}/v1/info</a></td></tr>
          <tr><td><code>POST</code></td><td><code>{$base}/v1/chunks</code> &mdash; auth required</td></tr>
          <tr><td><code>GET</code></td><td><code>{$base}/v1/chunks/{id}</code></td></tr>
          <tr><td><code>DELETE</code></td><td><code>{$base}/v1/chunks/{id}</code> &mdash; auth required</td></tr>
        </table>
        <p>See <code>README.md</code> for the full contract.</p>
        HTML;
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
        ]);
    }

    protected function handleUpload(): void
    {
        $keyId = $this->requireKey();
        if ($keyId === null) {
            return;
        }

        $declared = $this->contentLength();
        if ($declared === null) {
            Response::error(411, 'Content-Length required');

            return;
        }

        if ($declared > $this->config->maxChunkSize) {
            Response::error(413, 'chunk exceeds maxChunkSize');

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

        $this->gc->maybeSweep();

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
        if ($meta === null || !hash_equals($meta->ownerKeyId, $keyId)) {
            $this->notFound();

            return;
        }

        $this->store->delete($id);
        Response::noContent();
    }

    // -- helpers ------------------------------------------------------------

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

    /** Path of the request relative to the app root, e.g. "/v1/chunks". */
    protected function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        $base = $this->scriptDir();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = mb_substr($path, mb_strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

    /** Directory the front controller lives in, without a trailing slash. */
    protected function scriptDir(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    /**
     * Absolute base URL clients should use.
     *
     * Derived from the request unless config.php pins publicBaseUrl — which it
     * must when the app sits behind a reverse proxy that rewrites Host.
     */
    protected function publicBaseUrl(): string
    {
        if ($this->config->publicBaseUrl !== null) {
            return $this->config->publicBaseUrl;
        }

        $https = $_SERVER['HTTPS'] ?? '';
        $scheme = ($https !== '' && mb_strtolower((string) $https) !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . $this->scriptDir();
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
