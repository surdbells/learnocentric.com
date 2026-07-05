<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * Builds the shared DI container (loads .env + service definitions).
 * Used by both the HTTP bootstrap and the CLI console.
 */
return static function (): ContainerInterface {
    $root = dirname(__DIR__);

    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    // Ensure runtime dirs exist.
    foreach ([$root . '/var/log', $root . '/var/doctrine/proxies', $root . '/var/cache'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    $builder = new ContainerBuilder();
    $builder->addDefinitions($root . '/config/dependencies.php');

    return $builder->build();
};
