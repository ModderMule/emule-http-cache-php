<?php

declare(strict_types=1);

namespace EMule\HttpCache\Install;

use EMule\HttpCache\Config;
use EMule\HttpCache\Http\HtmlPage;
use EMule\HttpCache\Http\Request;
use EMule\HttpCache\Http\Response;

/**
 * Everything /install can be, in one place.
 *
 * index.php reaches this before a Config exists, and Router reaches it once one
 * does, so the installed and uninstalled paths run the same code and cannot
 * drift apart.
 *
 *   no config.php     GET   the settings form; nothing is written
 *                     POST  write config.php, then show the key once
 *   marker unclaimed  show the key once — the install ran but the page did not
 *   marker claimed    say so, and never show the key again
 *   no marker         a hand-written config: this page will not read it back
 */
class InstallController
{
    public function __construct(protected readonly Installer $installer)
    {
    }

    public function handle(string $method, string $path): void
    {
        if ($path !== '/install') {
            // Only reachable while uninstalled; Router owns every other path.
            InstallPage::notInstalled($this->baseUrl(), $path);

            return;
        }

        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
            header('Allow: GET, HEAD, POST');
            Response::error(405, 'method not allowed');

            return;
        }

        // A HEAD must never install anything, and must never claim the key.
        if ($method === 'HEAD') {
            HtmlPage::headers(200);

            return;
        }

        if (!$this->installer->isInstalled()) {
            if ($method === 'POST') {
                $this->install();

                return;
            }

            InstallPage::form($this->baseUrl(), InstallSettings::formDefaults(), [], Request::baseUrl());

            return;
        }

        $state = $this->installer->state();

        if ($state === null) {
            InstallPage::configuredByHand($this->baseUrl());

            return;
        }

        if (!$state->isClaimed()) {
            $this->disclose($state);

            return;
        }

        InstallPage::alreadyInstalled($this->baseUrl(), $state, $this->openUpload());
    }

    // -- internals ------------------------------------------------------------

    protected function install(): void
    {
        ['settings' => $settings, 'errors' => $errors] = InstallSettings::fromForm($_POST);

        if ($settings === null) {
            // Re-render with what they actually typed, not with what it clamped to.
            InstallPage::form($this->baseUrl(), $this->submitted(), $errors, Request::baseUrl());

            return;
        }

        $result = $this->installer->install($settings);

        if (!$result->ok || $result->secret === null) {
            InstallPage::failed($this->baseUrl(), $result);

            return;
        }

        $this->show($settings->keyId, $result->secret, $settings->openUpload);
    }

    /** The install ran but its page never rendered. Show the key, once. */
    protected function disclose(InstallState $state): void
    {
        $secret = $this->installer->secretFor($state->keyId);

        if ($secret === null) {
            InstallPage::configuredByHand($this->baseUrl());

            return;
        }

        $this->show($state->keyId, $secret, $this->openUpload());
    }

    /**
     * Claim first, render second.
     *
     * "Shown once" is the promise, so the disclosure has to be recorded before
     * the secret can reach the page. A claim that could not be written is said
     * out loud rather than papered over.
     */
    protected function show(string $keyId, string $secret, bool $openUpload): void
    {
        $claimed = $this->installer->claim();

        InstallPage::installed($this->baseUrl(), $keyId, $secret, $openUpload, $claimed);
    }

    /** @return array<string, string> the raw submission, one entry per form field */
    protected function submitted(): array
    {
        $values = InstallSettings::formDefaults();

        foreach (array_keys($values) as $field) {
            $raw = $_POST[$field] ?? null;
            $values[$field] = is_scalar($raw) ? trim((string) $raw) : '';
        }

        return $values;
    }

    protected function baseUrl(): string
    {
        $pinned = $this->config()?->publicBaseUrl;

        return $pinned ?? Request::baseUrl();
    }

    protected function openUpload(): bool
    {
        return $this->config()?->openUpload ?? false;
    }

    /** The live config, or null while there is none to read. */
    protected function config(): ?Config
    {
        if (!$this->installer->isInstalled()) {
            return null;
        }

        try {
            return Config::load($this->installer->baseDirectory());
        } catch (\Throwable) {
            return null;
        }
    }
}
