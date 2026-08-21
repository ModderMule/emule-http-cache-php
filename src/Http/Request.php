<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

/**
 * The bits of $_SERVER every entry point needs.
 *
 * Static because there is exactly one request per process and no test ever
 * needs two. It lives here rather than on Router because index.php has to know
 * the method and path *before* a Config exists — the installer gate runs first —
 * and two definitions of where the app is mounted would be one too many.
 */
class Request
{
    public static function method(): string
    {
        return mb_strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Path of the request relative to the app root, e.g. "/v1/chunks". */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        $base = self::scriptDir();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = mb_substr($path, mb_strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

    /** Directory the front controller lives in, without a trailing slash. */
    public static function scriptDir(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    /**
     * Absolute base URL derived from the request.
     *
     * Wrong behind a reverse proxy that terminates TLS or rewrites Host, which
     * is what config.php's publicBaseUrl is for — see Router::publicBaseUrl().
     */
    public static function baseUrl(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $scheme = ($https !== '' && mb_strtolower((string) $https) !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . self::scriptDir();
    }
}
