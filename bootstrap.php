<?php

declare(strict_types=1);

$autoload = isset($_composer_autoload_path) && is_string($_composer_autoload_path)
    ? $_composer_autoload_path
    : null;

if ($autoload === null || !is_file($autoload)) {
    $localAutoload = __DIR__ . '/vendor/autoload.php';
    $installedAutoload = dirname(__DIR__, 2) . '/autoload.php';

    if (is_file($localAutoload)) {
        $autoload = $localAutoload;
    } elseif (is_file($installedAutoload)) {
        $autoload = $installedAutoload;
    } else {
        throw new RuntimeException('Composer dependencies are required. Run `composer install` before using the standalone CLI.');
    }
}

require_once $autoload;
