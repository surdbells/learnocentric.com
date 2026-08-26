<?php

declare(strict_types=1);

/**
 * Environment-driven application settings.
 * Values come from apps/api/.env (loaded in bootstrap.php).
 */
return function (): array {
    $env = static fn (string $key, ?string $default = null): ?string => $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    $bool = static fn (string $key, bool $default = false): bool => filter_var($env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);

    return [
        'app' => [
            'env' => $env('APP_ENV', 'dev'),
            'debug' => $bool('APP_DEBUG', true),
            'url' => $env('APP_URL', 'http://127.0.0.1:8080'),
        ],
        'cors' => [
            'origins' => array_values(array_filter(array_map('trim', explode(',', (string) $env('CORS_ALLOWED_ORIGINS', ''))))),
        ],
        'db' => [
            'driver' => $env('DB_DRIVER', 'pdo_pgsql'),
            'host' => $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', '5432'),
            'dbname' => $env('DB_NAME', 'learnocentric'),
            'user' => $env('DB_USER', 'learno'),
            'password' => $env('DB_PASSWORD', ''),
        ],
        'jwt' => [
            'secret' => $env('JWT_SECRET', 'insecure-dev-secret'),
            'ttl' => (int) $env('JWT_TTL', '86400'),
            'issuer' => $env('JWT_ISSUER', 'learnocentric'),
        ],
        'mail' => [
            'api_url' => $env('ZEPTOMAIL_API_URL', 'https://api.zeptomail.com/v1.1/email'),
            'token' => $env('ZEPTOMAIL_TOKEN', ''),
            'from_address' => $env('MAIL_FROM_ADDRESS', 'noreply@learnocentric.com'),
            'from_name' => $env('MAIL_FROM_NAME', 'LearnoCentric'),
        ],
        'agora' => [
            'app_id' => $env('AGORA_APP_ID', ''),
            'app_certificate' => $env('AGORA_APP_CERTIFICATE', ''),
        ],
        'storage' => [
            'driver' => $env('STORAGE_DRIVER', 'local'),
            'local_root' => $env('STORAGE_LOCAL_ROOT', 'public/uploads'),
            'public_url' => rtrim((string) $env('STORAGE_PUBLIC_URL', 'http://127.0.0.1:8090/uploads'), '/'),
        ],
        'paystack' => [
            'api_url' => rtrim((string) $env('PAYSTACK_API_URL', 'https://api.paystack.co'), '/'),
            'secret_key' => $env('PAYSTACK_SECRET_KEY', ''),
            'public_key' => $env('PAYSTACK_PUBLIC_KEY', ''),
            'callback_url' => $env('PAYSTACK_CALLBACK_URL', ''),
        ],
        'doctrine' => [
            'dev_mode' => $bool('APP_DEBUG', true),
            'entity_paths' => [__DIR__ . '/../src/Domain/Entity'],
            'proxy_dir' => __DIR__ . '/../var/doctrine/proxies',
            'migrations' => [
                'table_storage' => ['table_name' => 'doctrine_migration_versions'],
                'migrations_paths' => ['App\\Migrations' => __DIR__ . '/../migrations'],
                'all_or_nothing' => true,
                'transactional' => true,
            ],
        ],
    ];
};
