<?php

declare(strict_types=1);

use App\Console\SeedCommand;
use App\Service\PasswordService;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command as Migrations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Symfony\Component\Console\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var \Psr\Container\ContainerInterface $container */
$container = (require dirname(__DIR__) . '/config/container.php')();

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
$settings = $container->get('settings');

$dependencyFactory = DependencyFactory::fromEntityManager(
    new ConfigurationArray($settings['doctrine']['migrations']),
    new ExistingEntityManager($em),
);

$app = new Application('LearnoCentric API Console');

// Doctrine ORM schema/validation commands.
ConsoleRunner::addCommands($app, new SingleManagerProvider($em));

// Doctrine Migrations commands.
$app->addCommands([
    new Migrations\DiffCommand($dependencyFactory),
    new Migrations\MigrateCommand($dependencyFactory),
    new Migrations\GenerateCommand($dependencyFactory),
    new Migrations\ExecuteCommand($dependencyFactory),
    new Migrations\StatusCommand($dependencyFactory),
    new Migrations\CurrentCommand($dependencyFactory),
    new Migrations\LatestCommand($dependencyFactory),
    new Migrations\ListCommand($dependencyFactory),
    new Migrations\UpToDateCommand($dependencyFactory),
    new Migrations\SyncMetadataCommand($dependencyFactory),
]);

// Application commands.
$app->add(new SeedCommand($em, $container->get(PasswordService::class), $container->get(App\Service\AnswerGrader::class)));

$app->run();
