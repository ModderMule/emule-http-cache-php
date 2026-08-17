<?php

declare(strict_types=1);

namespace EMule\HttpCache;

/**
 * Zero-dependency bootstrap.
 *
 * Deliberately no Composer: the whole point of this server is that an operator
 * can drop it into a webroot and have it work, and that a non-PHP backend can
 * mirror it from README.md alone.
 */

require_once __DIR__ . '/Autoloader.php';

Autoloader::register(__NAMESPACE__ . '\\', __DIR__);
