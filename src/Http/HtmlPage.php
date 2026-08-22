<?php

declare(strict_types=1);

namespace EMule\HttpCache\Http;

/**
 * The shell every HTML page in the app shares.
 *
 * There are six of them now — the status page and the five install states — and
 * they differ only in their body, so the doctype, stylesheet and heading live
 * once in src/Html/page.php, with the six bodies as templates beside it.
 */
class HtmlPage
{
    /**
     * Status line and headers, without a body. The HEAD path, and what send()
     * calls on its way in.
     *
     * $sensitive marks a page that has a credential on it: keep it out of caches,
     * out of indexes, and out of the next site's Referer header.
     */
    public static function headers(int $status, bool $sensitive = false): void
    {
        Response::status($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        if ($sensitive) {
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /**
     * The shell, then the body template straight after it.
     *
     * The shell closes no tags, so nothing has to follow the body and neither
     * template is ever held in memory as a string.
     *
     * @param array<string, string> $vars locals for the body template
     */
    public static function send(
        int $status,
        string $title,
        string $template,
        array $vars = [],
        bool $sensitive = false,
    ): void {
        self::headers($status, $sensitive);

        self::render('page', ['safeTitle' => self::escape($title)]);
        self::render($template, $vars);
    }

    /**
     * Echo one of the templates in src/Html with $vars as its locals.
     *
     * They are plain HTML with <?= ?> holes rather than heredocs, so an editor
     * sees markup instead of one long string. Values arrive ready to print: a
     * $safe* name has been through escape(), anything else is trusted markup
     * this code built itself.
     *
     * @param array<string, string> $vars
     */
    protected static function render(string $template, array $vars = []): void
    {
        // The closure is what keeps the caller's locals out of the template.
        (static function (string $__template, array $__vars): void {
            extract($__vars, EXTR_SKIP);

            require $__template;
        })(__DIR__ . '/../Html/' . $template . '.php', $vars);
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
