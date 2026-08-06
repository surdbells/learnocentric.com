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
    private const MAX_BYTES = 15 * 1024 * 1024; // 15 MB
    private const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt',
        'mp4', 'webm', 'mov', 'mp3', 'm4a', 'wav',
    ];

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
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            return Json::error($response, 'That file is too large. The limit is 15 MB.', 413);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'File upload failed.', 400);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            return Json::error($response, 'That file is too large. The limit is 15 MB.', 413);
        }

        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) {
            return Json::error($response, 'That file type is not allowed. Upload an image, document, audio or video file.', 422);
        }

        $path = date('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;

        $this->storage->writeStream($path, $file->getStream()->detach());

        // Path-only contract: the client stores the path and loads the file via
        // /backend/files/{path}; no absolute file URL is issued.
        return Json::write($response, [
            'path' => $path,
            'name' => $file->getClientFilename(),
            'size' => $file->getSize(),
            'type' => $file->getClientMediaType(),
        ], 201);
    }
}
