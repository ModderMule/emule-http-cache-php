<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

/**
 * Outcome of writing config.php.
 *
 * Either a generated secret and its marker, or a message plus the concrete
 * commands that would fix it — never both, hence the named constructors.
 */
class InstallResult
{
    /** @param list<string> $hints shell commands, in the order to run them */
    protected function __construct(
        public readonly bool $ok,
        public readonly ?string $secret = null,
        public readonly ?InstallState $state = null,
        public readonly string $error = '',
        public readonly array $hints = [],
    ) {
    }

    public static function installed(string $secret, InstallState $state): self
    {
        return new self(true, $secret, $state);
    }

    /** @param list<string> $hints */
    public static function failure(string $error, array $hints = []): self
    {
        return new self(false, null, null, $error, $hints);
    }
}
