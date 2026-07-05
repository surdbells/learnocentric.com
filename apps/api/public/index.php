<?php

declare(strict_types=1);

/** @var \Slim\App $app */
$app = require dirname(__DIR__) . '/config/bootstrap.php';
$app->run();
