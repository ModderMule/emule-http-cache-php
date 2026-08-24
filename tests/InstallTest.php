<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

use EMule\HttpCache\Config;
use EMule\HttpCache\Install\InstallPage;
use EMule\HttpCache\Install\InstallSettings;
use EMule\HttpCache\Install\Installer;
use EMule\HttpCache\Security\Ed2kConfigLink;

/**
 * The installer, and the link it prints.
 *
 * Every fixture starts from the *real* config.example.php rather than a copy
 * written here, because the generator works by substituting into that file:
 * if someone renames a setting or the sample key, this is the suite that says
 * so instead of a server that silently installs with the shipped secret.
 */
class InstallTest extends TestCase
{
    protected string $root = '';

    public function run(): void
    {
        printf("\n== eMule HTTP Cache install tests ==\n");

        $this->root = $this->makeTempDir();

        try {
            $this->checkFreshInstall();
            $this->checkSettingsSurvive();
            $this->checkMarker();
            $this->checkRefusals();
            $this->checkUnwritableBaseDir();
            $this->checkFormValidation();
            $this->checkLinkRoundTrip();
            $this->checkLinkRejections();
            $this->checkInstalledPage();
        } finally {
            $this->removeTree($this->root);
        }
    }

    // -- sections -------------------------------------------------------------

    protected function checkFreshInstall(): void
    {
        $this->section('Installer over a fresh directory');

        $dir = $this->appDir('fresh');
        $result = (new Installer($dir))->install($this->settings());

        $this->assert($result->ok, 'a fresh directory installs', $result->error);
        $this->assert(is_file($dir . '/config.php'), 'config.php is written');
        $this->assert(
            preg_match('/^[0-9a-f]{48}$/', (string) $result->secret) === 1,
            'the generated key is 48 hex characters',
        );

        $text = (string) @file_get_contents($dir . '/config.php');
        $this->assert(!str_contains($text, 'dev-only-change-me'), 'the shipped secret is nowhere in it');
        $this->assert(!str_contains($text, '?>'), 'no closing PHP tag');
        $this->assert(str_contains($text, 'Written by the installer'), 'it says where it came from');
        $this->assert(
            str_contains($text, 'Largest single chunk the server accepts'),
            "the sample's comments came along with it",
        );

        // Config::load() require()s the file, so reaching this at all proves it
        // is valid PHP.
        $config = Config::load($dir);
        $key = $config->apiKeys['default'] ?? null;

        $this->assert($key !== null, 'the key id from the form is the key id in config.php');
        $this->assert(
            $key !== null && hash_equals($key->secret, (string) $result->secret),
            'the key shown to the operator is the key that was stored',
        );
        $this->assert($key !== null && $key->enabled, 'a fresh key is enabled');
    }

    protected function checkSettingsSurvive(): void
    {
        $this->section('form settings reach config.php');

        $dir = $this->appDir('custom');
        $result = (new Installer($dir))->install($this->settings([
            'keyId' => 'seedbox',
            'openUpload' => '1',
            'openUploadQuotaGb' => '2.5',
            'quotaGb' => '6',
            'minFreeGb' => '0.5',
            'defaultTtlHours' => '12',
            'maxTtlHours' => '72',
            'publicBaseUrl' => 'https://cache.example.com/emule/',
        ]));

        $this->assert($result->ok, 'a fully customised install succeeds', $result->error);

        $config = Config::load($dir);

        $this->check('the key id', implode(',', array_keys($config->apiKeys)), 'seedbox');
        $this->check('that key\'s daily quota', $config->apiKeys['seedbox']->quotaBytesPerDay, 6 * 1_073_741_824);
        $this->assert($config->openUpload, 'the open-upload checkbox');
        $this->check('the anonymous allowance', $config->openUploadQuotaBytesPerDay, (int) (2.5 * 1_073_741_824));
        $this->check('the free-space floor', $config->minFreeBytes, (int) (0.5 * 1_073_741_824));
        $this->check('the default TTL, in seconds', $config->defaultTtl, 12 * 3600);
        $this->check('the maximum TTL, in seconds', $config->maxTtl, 72 * 3600);
        // Pinned with its trailing slash removed, which is what Router appends to.
        $this->check('the pinned base URL', (string) $config->publicBaseUrl, 'https://cache.example.com/emule');
    }

    protected function checkMarker(): void
    {
        $this->section('the install marker');

        $dir = $this->appDir('marker');
        $installer = new Installer($dir);
        $result = $installer->install($this->settings());

        $marker = (string) @file_get_contents($dir . '/var/install.json');
        $this->assert($marker !== '', 'var/install.json is written');
        $this->assert(
            !str_contains($marker, (string) $result->secret),
            'the marker holds no secret',
            'the key is in var/install.json as well as config.php',
        );

        $state = $installer->state();
        $this->assert($state !== null && !$state->isClaimed(), 'the key starts unclaimed');
        $this->assert($installer->claim(), 'claiming it succeeds');
        $this->assert($installer->state()?->isClaimed() === true, 'and the claim is on disk');

        $this->assert(
            hash_equals((string) $installer->secretFor('default'), (string) $result->secret),
            'secretFor() reads the key back for the one page allowed to show it',
        );

        // A config nobody generated has no marker, which is what stops /install
        // reading a hand-written key back to whoever asks.
        $hand = $this->appDir('by-hand');
        copy($hand . '/config.example.php', $hand . '/config.php');
        $this->assert((new Installer($hand))->state() === null, 'a hand-written config has no marker');
    }

    protected function checkRefusals(): void
    {
        $this->section('what the installer will not do');

        $dir = $this->appDir('twice');
        $installer = new Installer($dir);
        $installer->install($this->settings());

        $before = (string) @file_get_contents($dir . '/config.php');
        $again = $installer->install($this->settings());

        $this->assert(!$again->ok, 'installing over an existing config.php is refused');
        $this->check(
            'and leaves it byte-identical',
            hash('sha256', (string) @file_get_contents($dir . '/config.php')),
            hash('sha256', $before),
        );

        $first = (new Installer($this->appDir('rng-a')))->install($this->settings());
        $second = (new Installer($this->appDir('rng-b')))->install($this->settings());
        $this->assert(
            $first->secret !== $second->secret,
            'two installs generate two different keys',
        );
    }

    protected function checkUnwritableBaseDir(): void
    {
        $this->section('a directory the web server cannot write');

        $dir = $this->appDir('locked');
        @chmod($dir, 0o555);

        $probe = $dir . '/.probe';
        if (@touch($probe)) {
            @unlink($probe);
            @chmod($dir, 0o777);
            $this->skip('an unwritable base directory fails cleanly', 'this process can write to a 0555 directory');

            return;
        }

        $result = (new Installer($dir))->install($this->settings());
        @chmod($dir, 0o777);

        $this->assert(!$result->ok, 'an unwritable base directory fails');
        $this->assert($result->hints !== [], 'and hands back the commands that would fix it');
        $this->assert(!is_file($dir . '/config.php'), 'no config.php is left behind');
        $this->assert(glob($dir . '/config.php.tmp-*') === [], 'no half-written temporary file is left behind');
    }

    protected function checkFormValidation(): void
    {
        $this->section('InstallSettings validation');

        $refused = [
            'a ceiling below the default TTL' => ['defaultTtlHours' => '72', 'maxTtlHours' => '12'],
            'the reserved key id' => ['keyId' => 'anonymous'],
            'a key id with a slash in it' => ['keyId' => 'my/key'],
            'an empty key id' => ['keyId' => ''],
            'a relative base URL' => ['publicBaseUrl' => '/emule'],
            'a base URL with a query string' => ['publicBaseUrl' => 'https://h/?x=1'],
            'a quota that is not a number' => ['quotaGb' => 'lots'],
            'a negative quota' => ['minFreeGb' => '-1'],
            'a zero-hour TTL' => ['defaultTtlHours' => '0'],
        ];

        foreach ($refused as $label => $overrides) {
            $result = InstallSettings::fromForm(array_merge(InstallSettings::formDefaults(), $overrides));

            $this->assert(
                $result['settings'] === null && $result['errors'] !== [],
                $label . ' is refused, so nothing is written',
            );
        }

        $result = InstallSettings::fromForm(InstallSettings::formDefaults());
        $this->assert($result['settings'] !== null, 'the untouched form is valid as it stands');
        $this->assert(
            $result['settings']?->publicBaseUrl === null,
            'a blank base URL means "work it out per request"',
        );

        // Every field is reported at once: one page has to fix everything.
        $result = InstallSettings::fromForm([
            'keyId' => 'anonymous',
            'openUploadQuotaGb' => 'x',
            'quotaGb' => 'y',
            'minFreeGb' => 'z',
            'defaultTtlHours' => '0',
            'maxTtlHours' => '0',
            'publicBaseUrl' => 'nope',
        ]);
        $this->check('a wholly bad form reports every field', count($result['errors']), 7);
    }

    protected function checkLinkRoundTrip(): void
    {
        $this->section('Ed2kConfigLink');

        $vectors = [
            'the plain form' => [Ed2kConfigLink::DEFAULT_NAME, 'https://cache.example.com', '1f4b9c02d7e35a68', null],
            'with a key id' => [Ed2kConfigLink::DEFAULT_NAME, 'http://192.168.1.10/emule-http-cache-php', '1f4b9c02d7e35a68', 'default'],
            'a pipe in the name' => ['Nachbars WLAN | Cache', 'https://cache.example.com', 'abc123', 'seedbox'],
            'a non-ASCII name' => ['Zwischenspeicher für eMule', 'https://cache.example.com', 'abc123', null],
        ];

        foreach ($vectors as $label => [$name, $baseUrl, $secret, $keyId]) {
            $link = (new Ed2kConfigLink($name, $baseUrl, $secret, $keyId))->toString();
            $back = Ed2kConfigLink::parse($link);

            $this->assert(
                $back !== null
                    && $back->name === $name
                    && $back->baseUrl === $baseUrl
                    && $back->secret === $secret
                    && $back->keyId === $keyId,
                $label . ' round-trips',
                $link,
            );
        }

        $link = (new Ed2kConfigLink('Nachbars WLAN | Cache', 'https://h', 's'))->toString();
        $this->assert(
            substr_count($link, '|') === 5 && str_contains($link, '%7C'),
            'a literal pipe is escaped rather than becoming a field separator',
            $link,
        );

        $utf8 = (new Ed2kConfigLink('für', 'https://h', 's'))->toString();
        $this->assert(str_contains($utf8, 'f%C3%BCr'), 'non-ASCII is encoded as UTF-8 octets', $utf8);
    }

    protected function checkLinkRejections(): void
    {
        $this->section('Ed2kConfigLink rejections');

        $refused = [
            'the wrong link type' => 'ed2k://|file|x|1|abc|/',
            'too few fields' => 'ed2k://|httpcache|name|https://h|/',
            'no terminator' => 'ed2k://|httpcache|n|https://h|s',
            'a non-http scheme' => 'ed2k://|httpcache|n|ftp://h|s|/',
            'a relative base URL' => 'ed2k://|httpcache|n|/relative|s|/',
            'credentials in the base URL' => 'ed2k://|httpcache|n|https://u:p@h|s|/',
            'a query string' => 'ed2k://|httpcache|n|https://h?q=1|s|/',
            'a tail field without "="' => 'ed2k://|httpcache|n|https://h|s|junk|/',
            'an empty secret' => 'ed2k://|httpcache|n|https://h||/',
            'a malformed key id' => 'ed2k://|httpcache|n|https://h|s|k=has spaces|/',
            'a broken percent escape' => 'ed2k://|httpcache|n|https://h|s%ZZ|/',
            'a secret with whitespace' => 'ed2k://|httpcache|n|https://h|a%20b|/',
        ];

        foreach ($refused as $label => $link) {
            $this->assert(Ed2kConfigLink::parse($link) === null, $label . ' is refused');
        }

        $extended = Ed2kConfigLink::parse('ed2k://|httpcache|n|https://h|s|x=1|k=abc|/');
        $this->assert($extended?->keyId === 'abc', 'an unknown option is skipped, not fatal');

        $shouty = Ed2kConfigLink::parse('ED2K://|HTTPCACHE|n|https://h|s|/');
        $this->assert($shouty !== null, 'the scheme and type are case-insensitive');

        $long = 'ed2k://|httpcache|' . str_repeat('a', 5000) . '|https://h|s|/';
        $this->assert(Ed2kConfigLink::parse($long) === null, 'an absurdly long link is refused');
    }

    /**
     * The page that hands the link over.
     *
     * Copying is the only handover there is: eMuleQt's clipboard watcher matches
     * on the ed2k://|httpcache| prefix, and a Chromium since version 130 cannot
     * parse an ed2k:// URL at all — "|" is a forbidden host code point — so an
     * anchor there hands over nothing and takes the tab to about:blank#blocked.
     *
     * What the page shows must therefore be the canonical link, separators and
     * all. htmlspecialchars() leaves "|" alone, so the copied text survives; a
     * rendering that escaped the separators would look perfectly fine while
     * handing out a link no parser accepts, because a conforming one splits on
     * literal "|" before it decodes anything.
     */
    protected function checkInstalledPage(): void
    {
        $this->section('The installed page');

        $secret = str_repeat('a1b2c3d4', 6);

        // The suite has already printed, so the page's headers cannot go out and
        // PHP says so four times over. Only the body is under test — anything
        // else the render complains about still surfaces.
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'headers already sent')
                || str_contains($message, 'Cannot set response code');
        });

        ob_start();

        try {
            InstallPage::installed('http://192.168.1.10/emule-http-cache-php', 'default', $secret, false, true);
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        $this->assert(str_contains($html, 'id="copyLink"'), 'the page offers a copy control');

        if (preg_match('#<code id="ed2kLink">(.*?)</code>#s', $html, $m) !== 1) {
            $this->assert(false, 'the page shows a copyable ed2k link');

            return;
        }

        // What the browser hands the copy script is textContent, not markup.
        $copied = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $redacted = Ed2kConfigLink::redact($copied);

        $this->assert(
            str_starts_with($copied, 'ed2k://|httpcache|'),
            'the copied text carries the prefix the clipboard watcher matches on',
            $redacted,
        );
        $this->assert(
            !str_contains(mb_strtolower($copied), '%7c') && substr_count($copied, '|') === 6,
            'the copied link keeps its separators literal',
            $redacted,
        );

        $link = Ed2kConfigLink::parse($copied);
        $this->assert($link?->keyId === 'default', 'the copied link parses back', $redacted);
        $this->assert($link?->secret === $secret, 'the copied link carries the key that was just shown');

        // An anchor is inert in Chromium, so the page must not grow one back.
        $this->assert(!str_contains($html, 'href="ed2k'), 'the page has no ed2k:// anchor');
    }

    // -- fixtures ---------------------------------------------------------------

    /** A throwaway app directory holding the real config.example.php. */
    protected function appDir(string $name): string
    {
        $dir = $this->root . '/' . $name;

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException('could not create ' . $dir);
        }

        $sample = dirname(__DIR__) . '/config.example.php';
        if (!@copy($sample, $dir . '/config.example.php')) {
            throw new \RuntimeException('could not copy ' . $sample);
        }

        return $dir;
    }

    /** @param array<string, string> $overrides */
    protected function settings(array $overrides = []): InstallSettings
    {
        $result = InstallSettings::fromForm(array_merge(InstallSettings::formDefaults(), $overrides));

        if ($result['settings'] === null) {
            throw new \RuntimeException('the fixture does not validate: ' . implode('; ', $result['errors']));
        }

        return $result['settings'];
    }
}
