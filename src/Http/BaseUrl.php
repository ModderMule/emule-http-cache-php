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

    /**
     * Whether a base URL names the machine it is opened on: localhost, a name
     * under .localhost, or a loopback address.
     *
     * Such a URL is fine in the operator's own browser and useless in a link
     * meant for a client somewhere else.
     */
    public static function isLoopback(string $url): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = rtrim(mb_strtolower($host), '.');
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // parse_url keeps the brackets of an IPv6 literal.
        $address = trim($host, '[]');
        if (str_starts_with($address, '::ffff:')) {
            $address = substr($address, 7);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($address, '127.');
        }

        $packed = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($address) : false;

        return $packed !== false && $packed === inet_pton('::1');
    }
}
