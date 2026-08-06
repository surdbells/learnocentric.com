<?php

declare(strict_types=1);

namespace App\Application\Actions\Storage;

use App\Application\Support\Json;
use App\Service\Storage\StorageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /backend/files?p={path} — stream a stored file from Flysystem's local
 * adapter. Public (matching the prior static /uploads serving); paths are
 * unguessable 20-hex names. Append &download=1 to force a download. The path
 * travels in the query string so the request URL carries no static-file
 * extension (which the PHP dev server would otherwise intercept).
 */
final class FileServeAction
{
    public function __construct(private readonly StorageService $storage)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $path = ltrim((string) ($request->getQueryParams()['p'] ?? ''), '/');

        // Reject traversal attempts outright (the adapter also guards this).
        if ($path === '' || str_contains($path, '..')) {
            return Json::error($response, 'File not found.', 404);
        }
        if (!$this->storage->fileExists($path)) {
            return Json::error($response, 'File not found.', 404);
        }

        $resource = $this->storage->readStream($path);
        $stream = new Stream($resource);

        $disposition = $request->getQueryParams()['download'] ?? null;
        $name = basename($path);

        $response = $response
            ->withHeader('Content-Type', $this->storage->mimeType($path))
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withBody($stream);

        $size = $this->storage->fileSize($path);
        if ($size > 0) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }
        if ($disposition !== null) {
            $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        }

        return $response;
    }
}
