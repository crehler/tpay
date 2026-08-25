<?php

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

declare(strict_types=1);

// Installed into a shop first (the usual case), then a standalone checkout where Composer put
// the dependencies inside the package itself.
$autoloadPath = null;
foreach ([__DIR__ . '/../../../../vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoloadPath = $candidate;

        break;
    }
}

if ($autoloadPath === null) {
    throw new \RuntimeException('Vendor autoload not found — run composer install.');
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $autoloadPath;
$loader->addPsr4('Crehler\\Tpay\\Tests\\', __DIR__);
// The plugin may not be composer-installed in the shop root — register src for unit tests.
$loader->addPsr4('Crehler\\Tpay\\', __DIR__ . '/../src');
