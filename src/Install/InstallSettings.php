<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

use EMule\HttpCache\Config;
use EMule\HttpCache\Http\BaseUrl;

/**
 * The install form, once it has survived validation.
 *
 * The form asks in the units an operator thinks in — gigabytes and hours — and
 * this is where they become the bytes and seconds config.php stores. Nothing
 * reaches the Installer until every field has parsed.
 */
class InstallSettings
{
    protected const GIB = 1_073_741_824;
    protected const HOUR = 3_600;
    protected const MAX_GIGABYTES = 1_000_000;
    protected const MAX_HOURS = 8_760;

    public function __construct(
        public readonly string $keyId,
        public readonly bool $openUpload,
        public readonly int $openUploadQuotaBytesPerDay,
        public readonly int $quotaBytesPerDay,
        public readonly int $minFreeBytes,
        public readonly int $defaultTtl,
        public readonly int $maxTtl,
        public readonly ?string $publicBaseUrl,
    ) {
    }

    /** What the empty form shows. Raw strings, because that is what a form holds. */
    public static function formDefaults(): array
    {
        return [
            'keyId' => 'default',
            'openUpload' => '',
            'openUploadQuotaGb' => '10',
            'quotaGb' => '0',
            'minFreeGb' => '1',
            'defaultTtlHours' => '48',
            'maxTtlHours' => '168',
            'publicBaseUrl' => '',
        ];
    }

    /**
     * Validate one submission.
     *
     * @param  array<string, mixed> $input raw $_POST
     * @return array{settings: ?self, errors: array<string, string>} errors keyed by field name;
     *         settings is null unless every field passed, so a partial config can never be written
     */
    public static function fromForm(array $input): array
    {
        $errors = [];

        $keyId = trim((string) ($input['keyId'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $keyId) !== 1) {
            $errors['keyId'] = 'Letters, digits, dot, dash or underscore. 32 characters at most.';
        } elseif ($keyId === Config::ANONYMOUS_KEY_ID) {
            $errors['keyId'] = '"anonymous" is reserved for uploads that arrive without a key.';
        }

        // An unticked checkbox is simply absent from the submission.
        $openUpload = trim((string) ($input['openUpload'] ?? '')) !== '';

        $openQuota = self::gigabytes($input, 'openUploadQuotaGb', $errors);
        $quota = self::gigabytes($input, 'quotaGb', $errors);
        $minFree = self::gigabytes($input, 'minFreeGb', $errors);
        $defaultTtl = self::hours($input, 'defaultTtlHours', $errors);
        $maxTtl = self::hours($input, 'maxTtlHours', $errors);

        if ($defaultTtl !== null && $maxTtl !== null && $maxTtl < $defaultTtl) {
            $errors['maxTtlHours'] = 'The ceiling cannot be lower than the default.';
        }

        $publicBaseUrl = trim((string) ($input['publicBaseUrl'] ?? ''));
        if ($publicBaseUrl === '') {
            $publicBaseUrl = null;
        } else {
            $publicBaseUrl = BaseUrl::normalise($publicBaseUrl);
            if ($publicBaseUrl === null) {
                $errors['publicBaseUrl'] = 'An absolute http:// or https:// URL, with no query string.';
            }
        }

        if ($errors !== []) {
            return ['settings' => null, 'errors' => $errors];
        }

        return [
            'settings' => new self(
                keyId: $keyId,
                openUpload: $openUpload,
                openUploadQuotaBytesPerDay: (int) $openQuota,
                quotaBytesPerDay: (int) $quota,
                minFreeBytes: (int) $minFree,
                defaultTtl: (int) $defaultTtl,
                maxTtl: (int) $maxTtl,
                publicBaseUrl: $publicBaseUrl,
            ),
            'errors' => [],
        ];
    }

    // -- internals ------------------------------------------------------------

    /** @param array<string, string> $errors collected by reference */
    protected static function gigabytes(array $input, string $field, array &$errors): ?int
    {
        $raw = trim((string) ($input[$field] ?? ''));

        if (preg_match('/^\d+(\.\d+)?$/', $raw) !== 1) {
            $errors[$field] = 'A number of gigabytes, 0 or more.';

            return null;
        }

        $gigabytes = (float) $raw;
        if ($gigabytes > self::MAX_GIGABYTES) {
            $errors[$field] = 'That is more storage than anyone has.';

            return null;
        }

        return (int) round($gigabytes * self::GIB);
    }

    /** @param array<string, string> $errors collected by reference */
    protected static function hours(array $input, string $field, array &$errors): ?int
    {
        $raw = trim((string) ($input[$field] ?? ''));

        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            $errors[$field] = 'A whole number of hours, 1 or more.';

            return null;
        }

        $hours = (int) $raw;
        if ($hours > self::MAX_HOURS) {
            $errors[$field] = 'A year is the ceiling.';

            return null;
        }

        return $hours * self::HOUR;
    }
}
