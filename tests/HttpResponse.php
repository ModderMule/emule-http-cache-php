<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

/**
 * One HTTP response: status, headers, raw body.
 */
class HttpResponse
{
    /** @param array<string, string> $headers keyed by lower-cased header name */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** Case-insensitive header lookup. */
    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    /** The body decoded as JSON, or null when it is not a JSON structure. */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Body length in bytes — an HTTP payload is bytes, never characters. */
    public function size(): int
    {
        return strlen($this->body);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->body);
    }
}
