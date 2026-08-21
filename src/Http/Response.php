<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

/**
 * Response helpers.
 *
 * Everything the API returns is either JSON or an octet-stream body; there is
 * no template layer and no HTML except the one status page on "/".
 */
class Response
{
    public static function status(int $code): void
    {
        http_response_code($code);
    }

    /** @param array<string, mixed> $payload */
    public static function json(int $code, array $payload): void
    {
        self::status($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        // Content-Length is a byte count: mb_strlen() would under-report any
        // non-ASCII body and truncate the response.
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    /** Uniform error shape, so a non-PHP backend has something exact to mirror. */
    public static function error(int $code, string $message): void
    {
        self::json($code, ['error' => $message, 'status' => $code]);
    }

    public static function noContent(): void
    {
        self::status(204);
        header('Cache-Control: no-store');
    }
}
