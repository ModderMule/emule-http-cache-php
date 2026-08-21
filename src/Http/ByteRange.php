<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

/**
 * A single parsed byte range, per RFC 9110 §14.
 *
 * parse() returns null when the whole entity should be served: no Range header,
 * a unit other than bytes, or a multi-range request — which §14.2 explicitly
 * lets a server answer with a 200. That is a different outcome from a range
 * that cannot be satisfied, which comes back as an instance with $satisfiable
 * false and is a 416.
 */
class ByteRange
{
    protected function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly bool $satisfiable,
    ) {
    }

    public static function parse(?string $header, int $size): ?self
    {
        if ($header === null || $size <= 0) {
            return null;
        }

        if (preg_match('/^\s*bytes\s*=\s*(.+)$/iu', $header, $m) !== 1) {
            return null; // not a byte range unit — ignore per RFC 9110 §14.2
        }

        $spec = trim($m[1]);
        if (str_contains($spec, ',')) {
            return null; // multi-range: fall back to the full entity
        }

        if (preg_match('/^(\d*)-(\d*)$/u', $spec, $p) !== 1) {
            return self::unsatisfiable();
        }

        [$rawFirst, $rawLast] = [$p[1], $p[2]];

        if ($rawFirst === '' && $rawLast === '') {
            return self::unsatisfiable();
        }

        if ($rawFirst === '') {
            // Suffix form: "bytes=-500" means the final 500 bytes.
            $length = (int) $rawLast;
            if ($length <= 0) {
                return self::unsatisfiable();
            }

            return new self(max(0, $size - $length), $size - 1, true);
        }

        $from = (int) $rawFirst;
        if ($from >= $size) {
            return self::unsatisfiable();
        }

        $to = $rawLast === '' ? $size - 1 : min((int) $rawLast, $size - 1);

        return $to < $from ? self::unsatisfiable() : new self($from, $to, true);
    }

    /** Bytes covered, inclusive of both ends. */
    public function length(): int
    {
        return $this->to - $this->from + 1;
    }

    protected static function unsatisfiable(): self
    {
        return new self(0, 0, false);
    }
}
