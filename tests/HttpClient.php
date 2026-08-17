<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

/**
 * Minimal HTTP client over ext-curl.
 *
 * Only what the conformance suite needs, but with exact control over it: the
 * verb, the headers, whether a body carries a Content-Length, and the response
 * headers a Range assertion has to read back.
 */
class HttpClient
{
    public function __construct(protected readonly int $timeoutSeconds = 120)
    {
    }

    /** @param list<string> $headers raw "Name: value" lines */
    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    /** @param list<string> $headers */
    public function head(string $url, array $headers = []): HttpResponse
    {
        return $this->request('HEAD', $url, $headers);
    }

    /** @param list<string> $headers */
    public function post(string $url, string $body, array $headers = [], bool $chunked = false): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $chunked);
    }

    /** @param list<string> $headers */
    public function delete(string $url, array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $url, $headers);
    }

    /**
     * @param list<string> $headers
     * @param bool         $chunked send the body chunked, i.e. with no Content-Length
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        bool $chunked = false,
    ): HttpResponse {
        $collected = [];

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$collected): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $collected[mb_strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                // curl wants the byte count of the line it just handed us.
                return strlen($line);
            },
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($body !== null && $chunked) {
            $options += $this->chunkedUpload($body);
        } elseif ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);

        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5. The
        // handle is released when it goes out of scope.
        if ($response === false) {
            throw new \RuntimeException($method . ' ' . $url . ' failed: ' . curl_error($handle));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, $collected, is_string($response) ? $response : '');
    }

    /**
     * Feed the body through a read callback with no known size, which is what
     * makes libcurl pick Transfer-Encoding: chunked and omit Content-Length.
     *
     * @return array<int, mixed> extra curl options
     */
    protected function chunkedUpload(string $body): array
    {
        $offset = 0;

        return [
            CURLOPT_UPLOAD => true,
            CURLOPT_READFUNCTION => static function ($handle, $stream, int $length) use ($body, &$offset): string {
                // Byte offsets into a binary body, so substr(), not mb_substr().
                $slice = substr($body, $offset, $length);
                $offset += strlen($slice);

                return $slice;
            },
        ];
    }
}
