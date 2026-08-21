<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

/**
 * What counts as the base URL of a cache, in one place.
 *
 * Two callers need the same answer and must not disagree: the install form,
 * where an operator pins publicBaseUrl by hand, and Ed2kConfigLink, where the
 * same URL arrives from a stranger's clipboard.
 */
class BaseUrl
{
    /** Absolute http(s), a host, nothing else. Null when it is none of those. */
    public static function normalise(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if ((string) ($parts['host'] ?? '') === '') {
            return null;
        }

        // Credentials, a query or a fragment mean the sender is describing
        // something other than the root of an API.
        foreach (['user', 'pass', 'query', 'fragment'] as $unwanted) {
            if (isset($parts[$unwanted])) {
                return null;
            }
        }

        return rtrim($url, '/');
    }
}
