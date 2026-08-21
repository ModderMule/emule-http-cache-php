<?php

declare(strict_types=1);

namespace EMule\HttpCache\Security;

use EMule\HttpCache\Http\BaseUrl;

/**
 * An ed2k:// link that carries a cache endpoint and an upload credential.
 *
 *   ed2k://|httpcache|<name>|<baseUrl>|<secret>|/
 *
 * Three positional fields, then optional key=value ones of which only
 * k=<keyId> is defined — the same extensible shape eMule already uses for
 * ed2k://|file|name|size|hash|p=…|/. Full spec, including what a client must do
 * with one: docs/ed2k-httpcache-link.md.
 *
 * It lives beside ApiKey because that is what it is: a credential, in a form
 * that can be clicked.
 *
 * The server never consumes a link — parse() exists so the spec has a reference
 * implementation and so the encoding rules can be round-tripped in a test.
 */
class Ed2kConfigLink
{
    public const TYPE = 'httpcache';

    /** What the link is called when nothing better is to hand. */
    public const DEFAULT_NAME = 'HTTP Cache upload config';

    protected const MAX_LENGTH = 4096;
    protected const KEY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /** The constructor trusts its caller; parse() is where validation lives. */
    public function __construct(
        public readonly string $name,
        public readonly string $baseUrl,
        public readonly string $secret,
        public readonly ?string $keyId = null,
    ) {
    }

    public function toString(): string
    {
        $link = 'ed2k://|' . self::TYPE . '|'
            . self::encodeField($this->name) . '|'
            . self::encodeField($this->baseUrl) . '|'
            . self::encodeField($this->secret);

        if ($this->keyId !== null && $this->keyId !== '') {
            $link .= '|k=' . self::encodeField($this->keyId);
        }

        return $link . '|/';
    }

    /** Null for anything that is not a well-formed link of this type. */
    public static function parse(string $link): ?self
    {
        $link = trim($link);

        // Byte length, not character length: the cap is on what crosses the
        // wire, and a multibyte name must not buy extra room.
        if (strlen($link) > self::MAX_LENGTH) {
            return null;
        }

        $prefix = 'ed2k://|' . self::TYPE . '|';
        if (mb_strtolower(mb_substr($link, 0, mb_strlen($prefix))) !== $prefix) {
            return null;
        }

        if (!str_ends_with($link, '|/')) {
            return null;
        }

        $body = mb_substr($link, mb_strlen($prefix), mb_strlen($link) - mb_strlen($prefix) - 2);

        // Split first, decode second. The separator is the one character a
        // field may never carry literally, which is what makes this safe.
        $fields = explode('|', $body);
        if (count($fields) < 3) {
            return null;
        }

        $name = self::decodeField($fields[0]);
        $baseUrl = self::decodeField($fields[1]);
        $secret = self::decodeField($fields[2]);

        if ($name === null || $baseUrl === null || $secret === null) {
            return null;
        }

        $baseUrl = BaseUrl::normalise($baseUrl);
        if ($baseUrl === null || !self::isPlausibleSecret($secret)) {
            return null;
        }

        $keyId = null;
        foreach (array_slice($fields, 3) as $option) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9]*)=(.*)$/su', $option, $m) !== 1) {
                // A tail field that is not key=value is a malformed link, not an
                // extension: silently ignoring it would let a typo swallow a real
                // option one day.
                return null;
            }

            $value = self::decodeField($m[2]);
            if ($value === null) {
                return null;
            }

            // Unknown options are the extension point, and are skipped.
            if ($m[1] === 'k') {
                if (preg_match(self::KEY_ID_PATTERN, $value) !== 1) {
                    return null;
                }
                $keyId = $value;
            }
        }

        return new self($name, $baseUrl, $secret, $keyId);
    }

    // -- internals ------------------------------------------------------------

    /**
     * Percent-encode everything that is not a printable ASCII character, plus
     * the two that would otherwise be read as syntax.
     *
     * Byte-wise on purpose: percent-encoding is defined over octets, and walking
     * this with mb_substr() would emit one escape per character and mangle every
     * multibyte name.
     */
    protected static function encodeField(string $value): string
    {
        $out = '';

        for ($i = 0, $len = strlen($value); $i < $len; ++$i) {
            $byte = $value[$i];
            $code = ord($byte);

            if ($code >= 0x21 && $code <= 0x7E && $byte !== '|' && $byte !== '%') {
                $out .= $byte;

                continue;
            }

            $out .= sprintf('%%%02X', $code);
        }

        return $out;
    }

    /** Null when the field carries a percent sign that starts nothing valid. */
    protected static function decodeField(string $value): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            return null;
        }

        return rawurldecode($value);
    }

    /** A secret is opaque, but it is never empty and never has whitespace in it. */
    protected static function isPlausibleSecret(string $secret): bool
    {
        return $secret !== ''
            && mb_strlen($secret) <= 512
            && preg_match('/^[\x21-\x7E]+$/', $secret) === 1;
    }
}
