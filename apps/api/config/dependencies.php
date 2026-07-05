<?php

declare(strict_types=1);

use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\Mailer\ZeptoMailer;
use App\Service\PasswordService;
use App\Service\PermissionService;
use App\Service\Billing\PaystackClient;
use App\Service\Storage\StorageService;
use App\Service\Video\DailyClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * DI container definitions.
 */
return [
    'settings' => factory(function (): array {
        return (require __DIR__ . '/settings.php')();
    }),

    LoggerInterface::class => factory(function (ContainerInterface $c): LoggerInterface {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../var/log/app.log', Logger::DEBUG));
        return $logger;
    }),

    EntityManagerInterface::class => factory(function (ContainerInterface $c): EntityManagerInterface {
        $settings = $c->get('settings');
        $config = ORMSetup::createAttributeMetadataConfiguration(
            $settings['doctrine']['entity_paths'],
            $settings['doctrine']['dev_mode'],
            $settings['doctrine']['proxy_dir'],
        );
        $connection = DriverManager::getConnection([
            'driver' => $settings['db']['driver'],
            'host' => $settings['db']['host'],
            'port' => $settings['db']['port'],
            'dbname' => $settings['db']['dbname'],
            'user' => $settings['db']['user'],
            'password' => $settings['db']['password'],
            'charset' => 'utf8',
        ], $config);

        return new EntityManager($connection, $config);
    }),

    JwtService::class => factory(function (ContainerInterface $c): JwtService {
        $s = $c->get('settings')['jwt'];
        return new JwtService($s['secret'], (int) $s['ttl'], $s['issuer']);
    }),

    ZeptoMailer::class => factory(function (ContainerInterface $c): ZeptoMailer {
        $m = $c->get('settings')['mail'];
        return new ZeptoMailer($m['api_url'], $m['token'], $m['from_address'], $m['from_name'], $c->get(LoggerInterface::class));
    }),

    DailyClient::class => factory(function (ContainerInterface $c): DailyClient {
        $d = $c->get('settings')['daily'];
        return new DailyClient($d['api_url'], $d['api_key']);
    }),

    StorageService::class => factory(function (ContainerInterface $c): StorageService {
        $s = $c->get('settings')['storage'];
        // Resolve a relative local root against the api project directory.
        $root = $s['local_root'];
        if (!preg_match('/^([a-zA-Z]:|\/)/', $root)) {
            $root = dirname(__DIR__) . '/' . $root;
        }
        if (!is_dir($root)) {
            @mkdir($root, 0777, true);
        }
        return new StorageService($root, $s['public_url']);
    }),

    PaystackClient::class => factory(function (ContainerInterface $c): PaystackClient {
        $p = $c->get('settings')['paystack'];
        return new PaystackClient($p['api_url'], $p['secret_key'], $p['callback_url']);
    }),

    PasswordService::class => autowire(),
    PermissionService::class => autowire(),
    AuditLogger::class => autowire(),
    AuthService::class => autowire(),
];
