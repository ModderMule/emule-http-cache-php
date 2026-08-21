<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

use EMule\HttpCache\Config;

/**
 * Writes config.php on behalf of an operator who has a browser and nothing else.
 *
 * config.php is built out of the *text* of config.example.php rather than
 * emitted from scratch, so the sample's comments — the only documentation those
 * tuning knobs have — end up in the operator's real config. That makes the
 * generator a set of substitutions, which is only safe because step six loads
 * the result back and compares every field: a substitution that silently missed
 * cannot survive it.
 */
class Installer
{
    /** The two literals inside the sample's apiKeys block. Each appears once. */
    protected const SAMPLE_KEY_ID = "'local-test'";
    protected const SAMPLE_SECRET = 'dev-only-change-me-0123456789abcdef';

    protected const MARKER = 'install.json';

    public function __construct(protected readonly string $baseDir)
    {
    }

    public function baseDirectory(): string
    {
        return $this->baseDir;
    }

    public function isInstalled(): bool
    {
        return is_file($this->configPath());
    }

    public function configPath(): string
    {
        return $this->baseDir . '/config.php';
    }

    /** The marker, or null when this config was not machine-generated. */
    public function state(): ?InstallState
    {
        $raw = @file_get_contents($this->markerPath());
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? InstallState::fromArray($decoded) : null;
    }

    /**
     * Record that the key has now been shown.
     *
     * Called *before* the key reaches the page, never after: if this cannot be
     * written we have no way to stop the key being shown again, and the caller
     * has to say so rather than quietly break the promise.
     */
    public function claim(): bool
    {
        $state = $this->state();

        return $state !== null && $this->writeState($state->claimedNow(time()));
    }

    /** The configured secret for a key id, for the one page allowed to show it. */
    public function secretFor(string $keyId): ?string
    {
        try {
            $config = Config::load($this->baseDir);
        } catch (\Throwable) {
            return null;
        }

        return ($config->apiKeys[$keyId] ?? null)?->secret;
    }

    public function install(InstallSettings $settings): InstallResult
    {
        if ($this->isInstalled()) {
            return InstallResult::failure('config.php already exists, so there is nothing to install.');
        }

        $blocked = $this->preflight();
        if ($blocked !== null) {
            return $blocked;
        }

        $source = @file_get_contents($this->samplePath());
        if ($source === false) {
            return InstallResult::failure('config.example.php could not be read.');
        }

        $secret = bin2hex(random_bytes(24));

        $generated = $this->render($source, $settings, $secret);
        if ($generated === null) {
            return InstallResult::failure(
                'config.example.php is not shaped the way the installer expects, so nothing was written.',
                ['cp config.example.php config.php'],
            );
        }

        $written = $this->commit($generated);
        if ($written !== null) {
            return $written;
        }

        // Trust nothing about the substitutions: load the file back and compare.
        $mismatch = $this->verify($settings, $secret);
        if ($mismatch !== null) {
            @unlink($this->configPath());

            return InstallResult::failure('the generated config.php did not load correctly (' . $mismatch . '), so it was removed.');
        }

        // Unclaimed: the page that shows the key is what claims it.
        $state = new InstallState(time(), $settings->keyId, null);
        if (!$this->writeState($state)) {
            @unlink($this->configPath());

            return InstallResult::failure(
                'the install marker could not be written, so config.php was removed rather than leave a key nobody can see.',
                $this->chmodHints([$this->varDir()]),
            );
        }

        return InstallResult::installed($secret, $state);
    }

    /** Whoever this process is running as — the name to chown to. */
    public static function webServerUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && (string) ($info['name'] ?? '') !== '') {
                return (string) $info['name'];
            }
        }

        $user = get_current_user();

        return $user === '' ? 'the web server user' : $user;
    }

    // -- preflight ------------------------------------------------------------

    /** Null when everything is in place; otherwise every problem at once. */
    protected function preflight(): ?InstallResult
    {
        if (!is_readable($this->samplePath())) {
            return InstallResult::failure('config.example.php is missing, so there is no template to install from.');
        }

        $unwritable = [];

        if (!is_writable($this->baseDir)) {
            $unwritable[] = $this->baseDir;
        }

        foreach ([$this->storageDir(), $this->varDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                $unwritable[] = $dir;

                continue;
            }

            if (!is_writable($dir)) {
                $unwritable[] = $dir;
            }
        }

        if ($unwritable === []) {
            return null;
        }

        return InstallResult::failure(
            sprintf(
                'the web server (running as "%s") cannot write to %s.',
                self::webServerUser(),
                implode(', ', $unwritable),
            ),
            $this->chmodHints($unwritable),
        );
    }

    /** @param list<string> $paths @return list<string> */
    protected function chmodHints(array $paths): array
    {
        $quoted = implode(' ', array_map(static fn (string $p): string => escapeshellarg($p), $paths));

        return [
            'chmod 777 ' . $quoted,
            'sudo chown ' . self::webServerUser() . ' ' . $quoted,
        ];
    }

    // -- generation -----------------------------------------------------------

    /** The finished config.php, or null when the sample no longer matches. */
    protected function render(string $source, InstallSettings $settings, string $secret): ?string
    {
        $out = $this->replaceLeadingDocblock($source);
        if ($out === null) {
            return null;
        }

        foreach ([
            self::SAMPLE_KEY_ID => "'" . $settings->keyId . "'",
            self::SAMPLE_SECRET => $secret,
        ] as $needle => $replacement) {
            if (substr_count($out, (string) $needle) !== 1) {
                return null;
            }

            $out = str_replace((string) $needle, $replacement, $out);
        }

        $rewrites = [
            'quotaBytesPerDay' => [self::literal($settings->quotaBytesPerDay), self::describeBytes($settings->quotaBytesPerDay)],
            'openUpload' => [var_export($settings->openUpload, true), ''],
            'openUploadQuotaBytesPerDay' => [
                self::literal($settings->openUploadQuotaBytesPerDay),
                self::describeBytes($settings->openUploadQuotaBytesPerDay),
            ],
            'minFreeBytes' => [self::literal($settings->minFreeBytes), self::describeBytes($settings->minFreeBytes)],
            'defaultTtl' => [self::literal($settings->defaultTtl), self::describeSeconds($settings->defaultTtl)],
            'maxTtl' => [self::literal($settings->maxTtl), self::describeSeconds($settings->maxTtl)],
            'publicBaseUrl' => [var_export($settings->publicBaseUrl, true), ''],
        ];

        foreach ($rewrites as $key => [$value, $comment]) {
            $out = self::rewriteSetting($out, (string) $key, $value, $comment);
            if ($out === null) {
                return null;
            }
        }

        // The shipped secret must not survive anywhere, comments included.
        return mb_strpos($out, self::SAMPLE_SECRET) === false ? $out : null;
    }

    /**
     * Replace one `'key' => value,` line, keeping its indentation and rebuilding
     * its trailing comment — which would otherwise still read "// 48 hours" next
     * to a number that no longer means 48 hours.
     *
     * Anchored at the start of a line, so the commented-out example keys and the
     * prose above them are never touched. Null unless it matched exactly once.
     */
    protected static function rewriteSetting(string $source, string $key, string $value, string $comment): ?string
    {
        $pattern = '/^([ \t]*)\'' . preg_quote($key, '/') . '\'\s*=>[^\n]*$/m';

        if (preg_match_all($pattern, $source) !== 1) {
            return null;
        }

        $replaced = preg_replace_callback(
            $pattern,
            static function (array $m) use ($key, $value, $comment): string {
                $line = $m[1] . "'" . $key . "' => " . $value . ',';

                return $comment === '' ? $line : $line . '   // ' . $comment;
            },
            $source,
            1,
        );

        return is_string($replaced) ? $replaced : null;
    }

    /** Swap the sample's "copy me" docblock for one describing what this file is. */
    protected function replaceLeadingDocblock(string $source): ?string
    {
        $start = mb_strpos($source, '/**');
        $end = $start === false ? false : mb_strpos($source, '*/', $start);
        $body = mb_strpos($source, 'return [');

        if ($start === false || $end === false || $body === false || $start > $body) {
            return null;
        }

        $banner = "/**\n"
            . " * eMule HTTP Cache — configuration.\n"
            . " *\n"
            . ' * Written by the installer on ' . gmdate('Y-m-d\TH:i:s\Z') . ". Everything here is safe\n"
            . " * to edit by hand; the comments below explain each setting.\n"
            . " *\n"
            . " * This file contains an API secret. Keep it out of version control — .gitignore\n"
            . " * already covers it — and out of any backup you would hand to someone else.\n"
            . " *\n"
            . " * To start over with a fresh key: delete this file and open /install again.\n"
            . ' */';

        return mb_substr($source, 0, $start) . $banner . mb_substr($source, $end + 2);
    }

    /** Digit-grouped the way every other number in the sample is written. */
    protected static function literal(int $value): string
    {
        return number_format($value, 0, '', '_');
    }

    protected static function describeBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return 'unlimited';
        }

        $gib = $bytes / 1_073_741_824;

        return rtrim(rtrim(number_format($gib, 2, '.', ''), '0'), '.') . ' GiB';
    }

    protected static function describeSeconds(int $seconds): string
    {
        $hours = (int) round($seconds / 3600);

        return $hours % 24 === 0 && $hours >= 24
            ? sprintf('%d hours (%d days)', $hours, intdiv($hours, 24))
            : sprintf('%d hours', $hours);
    }

    // -- writing --------------------------------------------------------------

    /** Null on success, a failure result otherwise. */
    protected function commit(string $contents): ?InstallResult
    {
        $tmp = $this->baseDir . '/config.php.tmp-' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $contents) === false) {
            @unlink($tmp);

            return InstallResult::failure(
                'config.php could not be written to ' . $this->baseDir . '.',
                $this->chmodHints([$this->baseDir]),
            );
        }

        // 0644, not 0600: bin/gc.php and tests/smoke.php run as the shell user
        // while this file is written by the web server's, and 0600 would leave
        // them unable to read their own config. README says how to tighten it.
        @chmod($tmp, 0o644);

        // Same atomic commit as an uploaded chunk: no half-written config.php
        // can ever be require()d, whatever happens mid-write.
        if (!@rename($tmp, $this->configPath())) {
            @unlink($tmp);

            return InstallResult::failure(
                'config.php could not be created in ' . $this->baseDir . '.',
                $this->chmodHints([$this->baseDir]),
            );
        }

        return null;
    }

    /** Null when the reloaded config matches the form exactly; else what differed. */
    protected function verify(InstallSettings $settings, string $secret): ?string
    {
        try {
            $config = Config::load($this->baseDir);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        $key = $config->apiKeys[$settings->keyId] ?? null;
        if ($key === null) {
            return 'the key "' . $settings->keyId . '" is not in it';
        }

        if (!hash_equals($key->secret, $secret) || !$key->enabled) {
            return 'the generated key did not survive the write';
        }

        if ($key->quotaBytesPerDay !== $settings->quotaBytesPerDay) {
            return 'quotaBytesPerDay';
        }

        foreach ([
            'openUpload',
            'openUploadQuotaBytesPerDay',
            'minFreeBytes',
            'defaultTtl',
            'maxTtl',
            'publicBaseUrl',
        ] as $field) {
            if ($config->{$field} !== $settings->{$field}) {
                return $field;
            }
        }

        return null;
    }

    protected function writeState(InstallState $state): bool
    {
        $dir = $this->varDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }

        try {
            $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        } catch (\JsonException) {
            return false;
        }

        return @file_put_contents($this->markerPath(), $json) !== false;
    }

    // -- paths ----------------------------------------------------------------

    protected function samplePath(): string
    {
        return $this->baseDir . '/config.example.php';
    }

    protected function markerPath(): string
    {
        return $this->varDir() . '/' . self::MARKER;
    }

    /**
     * Read through Config so a hand-edited varDir is honoured. Before install
     * there is no config.php and Config::load() falls back to the sample, which
     * is exactly the directory the install is about to use.
     */
    protected function varDir(): string
    {
        return $this->configured('varDir', $this->baseDir . '/var');
    }

    protected function storageDir(): string
    {
        return $this->configured('storageDir', $this->baseDir . '/storage');
    }

    protected function configured(string $field, string $fallback): string
    {
        try {
            return (string) Config::load($this->baseDir)->{$field};
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
