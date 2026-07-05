<?php

declare(strict_types=1);

namespace App\Application\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class Json
{
    public static function write(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public static function error(Response $response, string $message, int $status = 400, array $extra = []): Response
    {
        return self::write($response, array_merge(['error' => $message], $extra), $status);
    }
}
