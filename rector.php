<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

/*
 * Pilnuje, by kod pozostał parsowalny na PHP 8.2 (dolna granica wspierana
 * przez Shopware). Set DOWN_TO_PHP_82 zdowngrade'uje każdą składnię 8.3+
 * (np. typowane stałe klasowe, #[\Override]). Uruchamiany lokalnie
 * (`vendor/bin/rector`) oraz w CI jako --dry-run (guard regresji).
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSets([
        DowngradeLevelSetList::DOWN_TO_PHP_82,
    ]);
