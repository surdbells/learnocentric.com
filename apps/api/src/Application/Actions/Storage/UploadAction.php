<?php

declare(strict_types=1);

namespace App\Application\Actions\Storage;

use App\Application\Support\Json;
use App\Service\Storage\StorageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/** POST /backend/upload — store an uploaded file and return its public URL. */
final class UploadAction
{
    public function __construct(private readonly StorageService $storage)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? $files['upload'] ?? (is_array($files) ? (reset($files) ?: null) : null);

        if (is_array($file)) {
            $file = $file[0] ?? null;
        }
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'No file provided (expected form field "file").', 422);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'File upload failed.', 400);
        }

        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        $path = date('Y/m') . '/' . bin2hex(random_bytes(10)) . ($ext !== '' ? '.' . $ext : '');

        $url = $this->storage->writeStream($path, $file->getStream()->detach());

        return Json::write($response, [
            'url' => $url,
            'path' => $path,
            'name' => $file->getClientFilename(),
            'size' => $file->getSize(),
            'type' => $file->getClientMediaType(),
        ], 201);
    }
}
