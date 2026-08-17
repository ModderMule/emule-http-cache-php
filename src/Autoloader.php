<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * PSR-4-ish autoloader.
 *
 * Deliberately no Composer: the whole point of this server is that an operator
 * can drop it into a webroot and have it work, and that a non-PHP backend can
 * mirror it from README.md alone.
 */
class Autoloader
{
    /**
     * Map a namespace prefix onto a directory.
     *
     * Registering several prefixes is safe. A loader that cannot resolve a class
     * returns without touching it and spl_autoload tries the next one, so the
     * src loader simply passes on EMule\HttpCache\Tests\* to the tests loader.
     */
    public static function register(string $prefix, string $baseDir): void
    {
        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = mb_substr($class, mb_strlen($prefix));
            $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
