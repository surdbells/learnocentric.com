<?php

declare(strict_types=1);

use App\Application\Middleware\CorsMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var \Psr\Container\ContainerInterface $container */
$container = (require __DIR__ . '/container.php')();

AppFactory::setContainer($container);
$app = AppFactory::create();

$settings = $container->get('settings');

// Inner → outer is the reverse of add order. We want:
// CORS (outermost) → Error → Routing → BodyParsing → route.
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware((bool) $settings['app']['debug'], true, true);
$app->add(new CorsMiddleware($settings['cors']['origins']));

(require __DIR__ . '/routes.php')($app);

return $app;
