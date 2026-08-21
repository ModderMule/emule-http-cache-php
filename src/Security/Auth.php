<?php

declare(strict_types=1);

namespace EMule\HttpCache\Security;

use EMule\HttpCache\Config;

/**
 * API-key authentication for the write endpoints.
 *
 * Only POST and DELETE are authenticated. GET /v1/chunks/{id} is deliberately
 * open: the 128-bit random id *is* the capability, and the body is ciphertext
 * the server cannot read. See README.md ("Why the download URL has no auth").
 */
class Auth
{
    /** The configured key id, or null when the credential is missing or wrong. */
    public static function identify(Config $config): ?string
    {
        $presented = self::presentedSecret();
        if ($presented === null || $presented === '') {
            return null;
        }

        // Compare against every configured key with a constant-time compare, and
        // do not break early: the number of comparisons must not depend on which
        // key matched.
        $match = null;
        foreach ($config->apiKeys as $keyId => $key) {
            if (hash_equals($key->secret, $presented)) {
                $match = (string) $keyId;
            }
        }

        return $match;
    }

    // -- internals ------------------------------------------------------------

    /** Read the bearer token from any of the places a proxy may have left it. */
    protected static function presentedSecret(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (!is_string($header) && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (mb_strtolower((string) $name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        if (is_string($header) && preg_match('/^\s*Bearer\s+(\S+)\s*$/iu', $header, $m) === 1) {
            return $m[1];
        }

        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

        return is_string($apiKey) ? trim($apiKey) : null;
    }
}
