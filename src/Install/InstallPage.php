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
        $action = HtmlPage::escape($baseUrl . '/install');

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

        HtmlPage::send(200, 'Install', <<<HTML
        <p>This server has no <code>config.php</code> yet. Nothing has been written to disk —
        choose your settings and press the button, and the next page will show you the API key
        <strong>once</strong>.</p>
        {$summary}
        <form method="post" action="{$action}">
        {$fields}
        <p><button type="submit">Write config.php</button></p>
        </form>
        <p class="muted">Every one of these is a plain line in <code>config.php</code> afterwards,
        with a comment explaining it. Nothing here is a one-way door.</p>
        HTML);
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

        HtmlPage::send(200, 'Installed', <<<HTML
        <p class="box warn"><strong>This is the only time the key is shown.</strong> Copy it now.
        If you lose it, it is still in <code>config.php</code> on the server; if you cannot read that
        file either, delete it and reload <a href="{$safeBase}/install">/install</a> for a new one.</p>
        {$claim}
        <h2>Your API key</h2>
        <table>
          <tr><td>key id</td><td><code>{$safeKeyId}</code></td></tr>
          <tr><td>secret</td><td><span class="key">{$safeSecret}</span></td></tr>
          <tr><td>base URL</td><td><code>{$safeBase}</code></td></tr>
        </table>
        {$open}
        <h2>Configure eMuleQt in one click</h2>
        <p><a href="{$safeLink}">{$safeLink}</a></p>
        <p class="muted">This link carries the secret. Treat it exactly like the key itself: it is
        for your own client, not for a forum post. See <code>docs/ed2k-httpcache-link.md</code>.</p>
        <h2>Or by hand</h2>
        <pre><code>httpCache:
          enabled: true
          baseUrl: "{$safeBase}"
          apiKey: "{$safeSecret}"</code></pre>
        <h2>Worth doing next</h2>
        <ul>
          <li>Check <a href="{$safeBase}/v1/info">{$safeBase}/v1/info</a> answers.</li>
          <li>Put <code>bin/gc.php</code> on cron as the web server user — see <code>README.md</code>.
              Without it, nothing reclaims expired chunks on a quiet server.</li>
          <li>If the web server user still owns this directory from the install, tighten it again.</li>
        </ul>
        HTML, sensitive: true);
    }

    public static function alreadyInstalled(string $baseUrl, InstallState $state, bool $openUpload): void
    {
        $safeBase = HtmlPage::escape($baseUrl);
        $shown = gmdate('Y-m-d H:i:s', (int) $state->claimedAt);
        $keyId = HtmlPage::escape($state->keyId);

        $open = $openUpload
            ? '<p class="box warn">This server accepts uploads without a key.</p>'
            : '';

        HtmlPage::send(200, 'Already installed', <<<HTML
        <p>This server is configured. The key for <code>{$keyId}</code> was shown once, on
        {$shown} UTC, and is not shown again.</p>
        {$open}
        <h2>If you need the key</h2>
        <p>It is in <code>config.php</code> in this directory. Read it over SSH or FTP.</p>
        <h2>If you want a new one</h2>
        <p>Delete <code>config.php</code> and reload
        <a href="{$safeBase}/install">{$safeBase}/install</a>. Chunks already stored keep working —
        they are downloaded without authentication — but nothing uploaded under the old key can be
        deleted through the API any more.</p>
        <p class="muted"><a href="{$safeBase}/">Server status</a></p>
        HTML);
    }

    public static function configuredByHand(string $baseUrl): void
    {
        $safeBase = HtmlPage::escape($baseUrl);

        HtmlPage::send(200, 'Already configured', <<<HTML
        <p>This server has a <code>config.php</code> that the installer did not write, so there is
        nothing for this page to do — and it will not read your key back to you.</p>
        <p>The key is in <code>config.php</code>, where you put it.</p>
        <p class="muted"><a href="{$safeBase}/">Server status</a></p>
        HTML);
    }

    public static function failed(string $baseUrl, InstallResult $result): void
    {
        $safeBase = HtmlPage::escape($baseUrl);
        $error = HtmlPage::escape($result->error);

        $hints = '';
        if ($result->hints !== []) {
            $commands = implode("\n", array_map(
                static fn (string $hint): string => HtmlPage::escape($hint),
                $result->hints,
            ));
            $hints = "<p>Run one of these on the server, then reload this page:</p>\n<pre><code>{$commands}</code></pre>";
        }

        HtmlPage::send(503, 'Install failed', <<<HTML
        <p class="box bad">Nothing was written: {$error}</p>
        {$hints}
        <h2>Or install it by hand</h2>
        <pre><code>cp config.example.php config.php
        php -r 'echo bin2hex(random_bytes(24)), "\\n";'</code></pre>
        <p>Paste that value over the <code>secret</code> in <code>config.php</code>. A config you
        wrote yourself is never read back by this page.</p>
        <p class="muted">Once it works, tighten the permissions again — the web server only needed
        to write here for this one request. <a href="{$safeBase}/install">Try again</a></p>
        HTML);
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

        HtmlPage::send(503, 'Not installed', <<<HTML
        <p>This server has not been configured yet, so it is not storing or serving anything.</p>
        <p>Open <a href="{$safeBase}/install">{$safeBase}/install</a> to finish the install.</p>
        HTML);
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
