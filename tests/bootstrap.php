<?php

declare(strict_types=1);

namespace EMule\HttpCache\Tests;

use EMule\HttpCache\Autoloader;

/**
 * Test bootstrap: the server's own autoloader plus this directory.
 */

require_once __DIR__ . '/../src/bootstrap.php';

Autoloader::register(__NAMESPACE__ . '\\', __DIR__);
