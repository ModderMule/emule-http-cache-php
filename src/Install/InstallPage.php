<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

use EMule\HttpCache\Http\HtmlPage;
use EMule\HttpCache\Http\Response;
use EMule\HttpCache\Security\Ed2kConfigLink;

/**
 * The five states of /install, plus the 503 every other route gets until it has
 * been through one of them.
 *
 * Only two of these ever put a secret on the page, and both go out as private:
 * no caching, no indexing, no Referer.
 */
class InstallPage
{
    /** @param array<string, string> $values @param array<string, string> $errors */
    public static function form(string $baseUrl, array $values, array $errors, string $detectedBaseUrl): void
    {
        $safeAction = HtmlPage::escape($baseUrl . '/install');

        $summary = $errors === [] ? '' : '<p class="box bad">Nothing has been written. '
            . 'Fix the fields marked below and submit again.</p>';

        $fields = self::text($values, $errors, 'keyId', 'Key id', 'Names this uploader in chunk metadata and in its quota counter. "anonymous" is reserved.')
            . self::checkbox($values, 'openUpload', 'Anyone can upload', 'Accept uploads with no API key at all. Convenient, and it means any stranger who finds the URL can spend your disk and your bandwidth. Anonymous chunks cannot be deleted through the API — they only lapse at their TTL.')
            . self::number($values, $errors, 'openUploadQuotaGb', 'Daily limit for anonymous uploads (GB)', 'Only applies when the box above is ticked. 0 means unlimited, which on an open server means "please fill my disk".')
            . self::number($values, $errors, 'quotaGb', 'Daily limit for this key (GB)', '0 means unlimited. Counted per UTC day.')
            . self::number($values, $errors, 'minFreeGb', 'Keep this much disk free (GB)', 'Uploads are refused with 507 once free space would drop below it, whoever is asking.')
            . self::number($values, $errors, 'defaultTtlHours', 'Default lifetime (hours)', 'Applied when the client asks for nothing specific.', '1')
            . self::number($values, $errors, 'maxTtlHours', 'Longest lifetime a client may ask for (hours)', 'Any longer request is clamped to this.', '1')
            . self::text($values, $errors, 'publicBaseUrl', 'Public base URL', 'Leave blank to work it out from each request. Pin it when a reverse proxy terminates TLS or rewrites Host, or peers get URLs on the wrong scheme.', HtmlPage::escape($detectedBaseUrl));

        HtmlPage::send(200, 'Install', 'install-form', [
            'safeAction' => $safeAction,
            'summary' => $summary,
            'fields' => $fields,
        ]);
    }

    public static function installed(
        string $baseUrl,
        string $keyId,
        string $secret,
        bool $openUpload,
        bool $claimRecorded,
    ): void {
        $link = (new Ed2kConfigLink(Ed2kConfigLink::DEFAULT_NAME, $baseUrl, $secret, $keyId))->toString();

        $safeBase = HtmlPage::escape($baseUrl);
        $safeKeyId = HtmlPage::escape($keyId);
        $safeSecret = HtmlPage::escape($secret);
        $safeLink = HtmlPage::escape($link);

        $open = $openUpload
            ? '<p class="box warn"><strong>Anyone can upload to this server.</strong> No key is needed
               for <code>POST /v1/chunks</code>. Anonymous chunks are owned by <code>anonymous</code>,
               which nobody can authenticate as, so they cannot be deleted through the API and only
               lapse at their TTL. Watch the disk, and keep the anonymous daily limit honest.</p>'
            : '';

        $claim = $claimRecorded
            ? ''
            : '<p class="box bad">The disclosure could not be recorded in <code>var/install.json</code>,
               so this page may show the key again. Check that <code>var/</code> is writable by the
               web server.</p>';

        HtmlPage::send(200, 'Installed', 'installed', [
            'safeBase' => $safeBase,
            'safeKeyId' => $safeKeyId,
            'safeSecret' => $safeSecret,
            'safeLink' => $safeLink,
            'claim' => $claim,
            'open' => $open,
        ], sensitive: true);
    }

    public static function alreadyInstalled(string $baseUrl, InstallState $state, bool $openUpload): void
    {
        $safeBase = HtmlPage::escape($baseUrl);
        $shown = gmdate('Y-m-d H:i:s', (int) $state->claimedAt);
        $safeKeyId = HtmlPage::escape($state->keyId);

        $open = $openUpload
            ? '<p class="box warn">This server accepts uploads without a key.</p>'
            : '';

        HtmlPage::send(200, 'Already installed', 'already-installed', [
            'safeBase' => $safeBase,
            'safeKeyId' => $safeKeyId,
            'shown' => $shown,
            'open' => $open,
        ]);
    }

    public static function configuredByHand(string $baseUrl): void
    {
        $safeBase = HtmlPage::escape($baseUrl);

        HtmlPage::send(200, 'Already configured', 'configured-by-hand', ['safeBase' => $safeBase]);
    }

    public static function failed(string $baseUrl, InstallResult $result): void
    {
        $safeBase = HtmlPage::escape($baseUrl);
        $safeError = HtmlPage::escape($result->error);

        $hints = '';
        if ($result->hints !== []) {
            $commands = implode("\n", array_map(
                static fn (string $hint): string => HtmlPage::escape($hint),
                $result->hints,
            ));
            $hints = "<p>Run one of these on the server, then reload this page:</p>\n<pre><code>{$commands}</code></pre>";
        }

        HtmlPage::send(503, 'Install failed', 'install-failed', [
            'safeBase' => $safeBase,
            'safeError' => $safeError,
            'hints' => $hints,
        ]);
    }

    /** What every other route answers until the server has been installed. */
    public static function notInstalled(string $baseUrl, string $path): void
    {
        // A machine client gets the uniform error shape rather than a page.
        if (str_starts_with($path, '/v1/')) {
            Response::error(503, 'server not installed');

            return;
        }

        $safeBase = HtmlPage::escape($baseUrl);

        HtmlPage::send(503, 'Not installed', 'not-installed', ['safeBase' => $safeBase]);
    }

    // -- form controls --------------------------------------------------------

    /** @param array<string, string> $values @param array<string, string> $errors */
    protected static function text(array $values, array $errors, string $name, string $label, string $hint, string $placeholder = ''): string
    {
        $attributes = 'type="text"' . ($placeholder === '' ? '' : ' placeholder="' . $placeholder . '"');

        return self::input($values, $errors, $name, $label, $hint, $attributes);
    }

    /** @param array<string, string> $values @param array<string, string> $errors */
    protected static function number(array $values, array $errors, string $name, string $label, string $hint, string $min = '0'): string
    {
        return self::input($values, $errors, $name, $label, $hint, 'type="number" step="any" min="' . $min . '"');
    }

    /** @param array<string, string> $values @param array<string, string> $errors */
    protected static function input(array $values, array $errors, string $name, string $label, string $hint, string $attributes): string
    {
        $safeName = HtmlPage::escape($name);
        $value = HtmlPage::escape($values[$name] ?? '');
        $error = isset($errors[$name])
            ? '<small style="color:#dc2626">' . HtmlPage::escape($errors[$name]) . '</small>'
            : '';

        return '<label><span>' . HtmlPage::escape($label) . '</span>'
            . '<small>' . HtmlPage::escape($hint) . '</small>'
            . '<input ' . $attributes . ' name="' . $safeName . '" id="' . $safeName . '" value="' . $value . '">'
            . $error
            . "</label>\n";
    }

    /** @param array<string, string> $values */
    protected static function checkbox(array $values, string $name, string $label, string $hint): string
    {
        $safeName = HtmlPage::escape($name);
        $checked = ($values[$name] ?? '') === '' ? '' : ' checked';

        return '<div class="check"><input type="checkbox" value="1" name="' . $safeName . '" id="' . $safeName . '"' . $checked . '>'
            . '<label for="' . $safeName . '" style="margin:0"><span>' . HtmlPage::escape($label) . '</span>'
            . '<small>' . HtmlPage::escape($hint) . '</small></label></div>' . "\n";
    }
}
