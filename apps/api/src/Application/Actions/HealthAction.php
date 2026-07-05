<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\Support\Json;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class HealthAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $db = true;
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (Throwable) {
            $db = false;
        }

        return Json::write($response, [
            'status' => $db ? 'ok' : 'degraded',
            'service' => 'learnocentric-api',
            'time' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'db' => $db,
        ], $db ? 200 : 503);
    }
}
