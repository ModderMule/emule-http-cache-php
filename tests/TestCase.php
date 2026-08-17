<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

/**
 * Minimal test harness.
 *
 * No PHPUnit, for the same reason there is no Composer: the suite has to run
 * from a bare checkout on any machine that has PHP. A subclass implements
 * run() and reports through ok()/bad()/check()/assert().
 */
abstract class TestCase
{
    protected int $passed = 0;
    protected int $failed = 0;
    protected bool $colour;

    public function __construct()
    {
        $this->colour = $this->supportsColour();
    }

    /** Every assertion in the suite. */
    abstract public function run(): void;

    /** Run the suite, print the tally, return the process exit code. */
    public function execute(): int
    {
        $this->run();

        printf("\n%d passed, %d failed\n\n", $this->passed, $this->failed);

        return $this->failed === 0 ? 0 : 1;
    }

    /** Raise memory_limit to at least $bytes. Never lowers a higher limit. */
    public static function ensureMemoryLimit(int $bytes): void
    {
        $current = self::memoryLimitBytes();

        if ($current !== null && $current < $bytes) {
            ini_set('memory_limit', (string) $bytes);
        }
    }

    protected function section(string $title): void
    {
        printf("\n%s\n", $title);
    }

    protected function ok(string $label): void
    {
        ++$this->passed;
        printf("  %s   %s\n", $this->paint('ok', '32'), $label);
    }

    protected function bad(string $label): void
    {
        ++$this->failed;
        printf("  %s %s\n", $this->paint('FAIL', '31'), $label);
    }

    /** Compare as strings, so an int status and a "200" body field both work. */
    protected function check(string $label, string|int $actual, string|int $expected): void
    {
        if ((string) $actual === (string) $expected) {
            $this->ok($label);

            return;
        }

        $this->bad(sprintf("%s (expected '%s', got '%s')", $label, $expected, $actual));
    }

    protected function assert(bool $passed, string $label, string $detail = ''): void
    {
        if ($passed) {
            $this->ok($label);

            return;
        }

        $this->bad($detail === '' ? $label : $label . ' (' . $detail . ')');
    }

    protected function paint(string $text, string $code): string
    {
        return $this->colour ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }

    /** ANSI only on a TTY, and on Windows only once VT100 is switched on. */
    protected function supportsColour(): bool
    {
        if (!function_exists('stream_isatty') || !stream_isatty(STDOUT)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            return true;
        }

        return function_exists('sapi_windows_vt100_support')
            && sapi_windows_vt100_support(STDOUT, true);
    }

    /** A php.ini shorthand size ("256M") in bytes; null when unlimited. */
    protected static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (mb_strtolower(mb_substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
