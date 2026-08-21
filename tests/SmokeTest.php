<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

/**
 * The contract test — 31 assertions over the whole REST surface.
 *
 * Talks nothing but HTTP, so it is equally valid against a Go, Rust or
 * nginx+object-store backend: point it at any implementation and it must pass
 * unchanged. See README.md for the contract itself.
 */
class SmokeTest extends TestCase
{
    /** One full eMule part — PARTSIZE from src/core/utils/Opcodes.h. */
    protected const PART_SIZE = 9_728_000;

    /** PKCS#7 on an exact multiple of the block size appends a whole extra block. */
    protected const CIPHER_SIZE = 9_728_016;

    protected HttpClient $http;

    protected string $plain = '';
    protected string $cipher = '';
    protected string $key = '';
    protected string $iv = '';

    protected int $maxChunkSize = 0;
    protected bool $uploadRequiresAuth = true;
    protected string $chunkId = '';
    protected string $chunkUrl = '';

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
    ) {
        parent::__construct();

        $this->http = new HttpClient();
    }

    public function run(): void
    {
        printf("\n== eMule HTTP Cache smoke test ==\n");
        printf("base: %s\n", $this->baseUrl);

        $this->checkInfo();
        $this->preparePayload();
        $this->checkAuth();
        $this->checkUpload();
        $this->checkDownload();
        $this->checkRanges();
        $this->checkDelete();
        $this->checkMisc();
    }

    // -- sections -------------------------------------------------------------

    protected function checkInfo(): void
    {
        $this->section('/v1/info');

        $response = $this->http->get($this->baseUrl . '/v1/info');
        $info = $response->json() ?? [];

        $this->assert(
            ($info['service'] ?? null) === 'emule-http-cache',
            'reports service name',
            'service name missing: ' . $response->body,
        );
        $this->assert(
            ($info['rangeSupported'] ?? null) === true,
            'advertises Range support',
            'rangeSupported missing',
        );

        // Absent means required: a backend written before the field existed is a
        // closed one, and guessing the other way would skip a real assertion.
        $this->uploadRequiresAuth = ($info['uploadRequiresAuth'] ?? true) !== false;

        $this->maxChunkSize = (int) ($info['maxChunkSize'] ?? 0);
        $this->assert(
            $this->maxChunkSize >= self::CIPHER_SIZE,
            sprintf('maxChunkSize %d fits a part', $this->maxChunkSize),
            sprintf('%d < %d', $this->maxChunkSize, self::CIPHER_SIZE),
        );
    }

    protected function preparePayload(): void
    {
        $this->section('prepare payload');

        $this->plain = random_bytes(self::PART_SIZE);
        $this->key = random_bytes(32);
        $this->iv = random_bytes(16);

        $cipher = openssl_encrypt($this->plain, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($cipher === false) {
            throw new \RuntimeException('openssl_encrypt failed: ' . (openssl_error_string() ?: 'unknown error'));
        }
        $this->cipher = $cipher;

        // Byte counts throughout: this is binary ciphertext, not text.
        $this->check('plaintext is one full part', strlen($this->plain), self::PART_SIZE);
        $this->check('ciphertext is part + one pad block', strlen($this->cipher), self::CIPHER_SIZE);
    }

    protected function checkAuth(): void
    {
        $this->section('auth');

        $response = $this->http->post($this->baseUrl . '/v1/chunks', $this->cipher, [
            'Authorization: Bearer definitely-not-the-key',
            'Content-Type: application/octet-stream',
        ]);
        $this->check('POST with a wrong key is rejected', $response->status, 401);

        // A few bytes rather than a whole part: on an open server this one is
        // stored, and an anonymous chunk cannot be deleted afterwards.
        $response = $this->http->post($this->baseUrl . '/v1/chunks', 'no-key-probe', [
            'Content-Type: application/octet-stream',
        ]);

        if ($this->uploadRequiresAuth) {
            $this->check('POST with no key is rejected', $response->status, 401);
        } else {
            // The wrong key above must still be a 401 even here: only an absent
            // credential falls through to anonymous.
            $this->check('POST with no key is accepted on an open server', $response->status, 201);
        }

        // Chunked transfer omits Content-Length, which the contract requires.
        $response = $this->http->post($this->baseUrl . '/v1/chunks', $this->cipher, [
            $this->bearer(),
            'Content-Type: application/octet-stream',
        ], chunked: true);
        $this->check('POST without Content-Length is rejected', $response->status, 411);
    }

    protected function checkUpload(): void
    {
        $this->section('POST /v1/chunks');

        $response = $this->http->post($this->baseUrl . '/v1/chunks', $this->cipher, [
            $this->bearer(),
            'Content-Type: application/octet-stream',
            'X-Chunk-TTL: 600',
        ]);
        $this->check('returns 201', $response->status, 201);

        $body = $response->json() ?? [];
        $this->chunkUrl = (string) ($body['url'] ?? '');
        $this->chunkId = (string) ($body['id'] ?? '');

        $this->assert(
            $this->chunkUrl !== '',
            'returns a url (' . $this->chunkUrl . ')',
            'no url in: ' . $response->body,
        );
        $this->assert(
            preg_match('/^[0-9a-f]{32}$/u', $this->chunkId) === 1,
            'id is 128 bits of hex',
            'bad id: ' . $this->chunkId,
        );
        $this->check('echoes the stored size', (int) ($body['size'] ?? 0), self::CIPHER_SIZE);

        if ($this->chunkUrl === '') {
            throw new \RuntimeException('upload did not return a url — the rest of the suite cannot run');
        }
    }

    protected function checkDownload(): void
    {
        $this->section('GET the chunk');

        $response = $this->http->get($this->chunkUrl);

        $this->check('ciphertext round-trips byte for byte', $response->sha256(), hash('sha256', $this->cipher));
        $this->assert(
            mb_strtolower(trim((string) $response->header('Accept-Ranges'))) === 'bytes',
            'advertises Accept-Ranges',
            'no Accept-Ranges header',
        );
        $this->assert(
            mb_strtolower((string) $response->header('Content-Type')) === 'application/octet-stream',
            'serves application/octet-stream',
            'wrong Content-Type: ' . (string) $response->header('Content-Type'),
        );

        $plain = openssl_decrypt($response->body, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
        $this->check(
            'decrypts back to the original part',
            hash('sha256', is_string($plain) ? $plain : ''),
            hash('sha256', $this->plain),
        );
    }

    protected function checkRanges(): void
    {
        $this->section('Range requests');

        $response = $this->http->get($this->chunkUrl, ['Range: bytes=1000-1999']);
        $this->check('ranged GET returns 206', $response->status, 206);
        $this->check('ranged GET returns the asked-for length', $response->size(), 1000);
        $this->check(
            'Content-Range is exact',
            (string) $response->header('Content-Range'),
            'bytes 1000-1999/' . self::CIPHER_SIZE,
        );
        // Byte offsets into binary ciphertext, so substr(), not mb_substr().
        $this->check('ranged bytes match the source', $response->sha256(), hash('sha256', substr($this->cipher, 1000, 1000)));

        // Open-ended range: this is what a resuming downloader actually sends.
        $resume = self::CIPHER_SIZE - 4096;
        $response = $this->http->get($this->chunkUrl, ['Range: bytes=' . $resume . '-']);
        $this->check('open-ended range returns the rest', $response->size(), 4096);

        $response = $this->http->get($this->chunkUrl, ['Range: bytes=-16']);
        $this->check('suffix range returns the last bytes', $response->size(), 16);

        $response = $this->http->get($this->chunkUrl, ['Range: bytes=999999999-']);
        $this->check('unsatisfiable range returns 416', $response->status, 416);

        $response = $this->http->head($this->chunkUrl);
        $this->check('HEAD works', $response->status, 200);
    }

    protected function checkDelete(): void
    {
        $this->section('DELETE /v1/chunks/{id}');

        $url = $this->baseUrl . '/v1/chunks/' . $this->chunkId;

        $response = $this->http->delete($url);
        $this->check('DELETE without a key is rejected', $response->status, 401);

        $response = $this->http->delete($url, [$this->bearer()]);
        $this->check('DELETE with the owner key succeeds', $response->status, 204);

        $response = $this->http->get($this->chunkUrl);
        $this->check('the chunk is gone afterwards', $response->status, 404);
    }

    protected function checkMisc(): void
    {
        $this->section('misc');

        $response = $this->http->get($this->baseUrl . '/v1/chunks/00000000000000000000000000000000');
        $this->check('unknown id is a 404', $response->status, 404);

        $response = $this->http->get($this->baseUrl . '/v1/chunks/not-a-valid-id');
        $this->check('malformed id is a 404', $response->status, 404);

        $oversized = random_bytes($this->maxChunkSize + 1024);
        $response = $this->http->post($this->baseUrl . '/v1/chunks', $oversized, [
            $this->bearer(),
            'Content-Type: application/octet-stream',
        ]);
        unset($oversized);
        $this->check('oversized chunk is rejected', $response->status, 413);

        $response = $this->http->request('PUT', $this->baseUrl . '/v1/chunks', [$this->bearer()]);
        $this->check('unsupported method is a 405', $response->status, 405);
    }

    // -- internals ------------------------------------------------------------

    protected function bearer(): string
    {
        return 'Authorization: Bearer ' . $this->apiKey;
    }
}
