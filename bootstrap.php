<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Composer dependencies are required. Run `composer install` before using the standalone CLI.');
}

require $autoload;
