<?php

declare(strict_types=1);

namespace App\Application\Actions\Content;

use App\Application\Support\Json;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * /backend/content/library — the platform content library (super admin only).
 * Upload/curate resources, record their licence, and take items down.
 */
final class ContentLibraryAction
{
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx',
        'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'mp4', 'webm', 'mov', 'mp3', 'm4a', 'wav',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageService $storage,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        $items = $this->em->getRepository(ContentResource::class)->findBy([], ['createdAt' => 'DESC']);
        return Json::write($response, array_map(static fn (ContentResource $r) => $r->toArray(), $items));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Json::error($response, 'A title is required.', 422);
        }

        $resource = new ContentResource($title, (string) ($body['contentType'] ?? 'document'));
        $this->applyFields($resource, $body);
        $resource->setCreatedBy($request->getAttribute('user'));

        // A resource cannot enter the library without a documented licence + source (spec §17).
        if (($err = $this->assertLicensed($resource, $response)) !== null) {
            return $err;
        }

        // Optional file (multipart field "file").
        $file = $this->pickFile($request);
        if ($file !== null) {
            $stored = $this->storeFile($file, $response);
            if ($stored instanceof Response) {
                return $stored;
            }
            $resource->setFile($stored['url'], $stored['name'], $stored['size']);
        }

        $this->em->persist($resource);
        $this->em->flush();
        $this->audit->log('content.create', $request->getAttribute('user'), 'ContentResource', (string) $resource->getId(), null, $resource->toArray());

        return Json::write($response, $resource->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $resource = $this->em->getRepository(ContentResource::class)->find((int) ($body['id'] ?? 0));
        if ($resource === null) {
            return Json::error($response, 'Content not found.', 404);
        }
        $before = $resource->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $resource->setTitle((string) $body['title']);
        }

        $this->applyFields($resource, $body);

        // Licence takedown / restore. Approving requires a documented licence + source (spec §17).
        if (isset($body['licence_status'])) {
            if ($body['licence_status'] === ContentResource::TAKEDOWN) {
                $resource->takedown(isset($body['takedown_reason']) ? (string) $body['takedown_reason'] : null);
            } elseif ($body['licence_status'] === ContentResource::APPROVED) {
                if (($err = $this->assertLicensed($resource, $response)) !== null) {
                    return $err;
                }
                $resource->restore();
            }
        }

        $this->em->flush();
        $this->audit->log('content.update', $request->getAttribute('user'), 'ContentResource', (string) $resource->getId(), $before, $resource->toArray());

        return Json::write($response, $resource->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $resource = $this->em->getRepository(ContentResource::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($resource === null) {
            return Json::error($response, 'Content not found.', 404);
        }
        $this->em->remove($resource);
        $this->em->flush();
        $this->audit->log('content.delete', $request->getAttribute('user'), 'ContentResource', (string) ($request->getQueryParams()['id'] ?? ''), null, null);

        return Json::write($response, ['deleted' => true]);
    }

    private function applyFields(ContentResource $r, array $body): void
    {
        if (array_key_exists('contentType', $body)) { $r->setContentType((string) $body['contentType']); }
        if (array_key_exists('subjectArea', $body)) { $r->setSubjectArea($this->str($body['subjectArea'])); }
        if (array_key_exists('gradeLevel', $body)) { $r->setGradeLevel($this->str($body['gradeLevel'])); }
        if (array_key_exists('description', $body)) { $r->setDescription($this->str($body['description'])); }
        if (array_key_exists('difficultyLevel', $body)) { $r->setDifficultyLevel($this->str($body['difficultyLevel'])); }
        if (array_key_exists('tags', $body)) { $r->setTags($this->tags($body['tags'])); }
        if (array_key_exists('isPremium', $body)) { $r->setIsPremium(filter_var($body['isPremium'], FILTER_VALIDATE_BOOL)); }
        if (array_key_exists('source', $body)) { $r->setSource($this->str($body['source'])); }
        if (array_key_exists('licence', $body)) { $r->setLicence((string) $body['licence']); }
        if (array_key_exists('audience', $body)) { $r->setAudience((string) $body['audience']); }
        if (array_key_exists('visibility', $body)) { $r->setVisibility((string) $body['visibility']); }
        if (array_key_exists('downloadable', $body)) { $r->setDownloadable(filter_var($body['downloadable'], FILTER_VALIDATE_BOOL)); }
    }

    /**
     * A resource may only be served/approved once its provenance is documented:
     * a non-empty source and a recorded licence (spec §17).
     */
    private function assertLicensed(ContentResource $r, Response $response): ?Response
    {
        if ($this->str($r->getSource()) === null || trim($r->getLicence()) === '') {
            return Json::error($response, 'A source and licence are required before a resource can be served.', 422);
        }
        return null;
    }

    private function pickFile(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? $files['upload'] ?? null;
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }
        return $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /** @return array{url:string,name:string,size:int}|Response */
    private function storeFile(UploadedFileInterface $file, Response $response): array|Response
    {
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE || ($file->getSize() ?? 0) > self::MAX_BYTES) {
            return Json::error($response, 'That file is too large. The limit is 15 MB.', 413);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'File upload failed.', 400);
        }
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) {
            return Json::error($response, 'That file type is not allowed.', 422);
        }
        $path = 'library/' . date('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
        $url = $this->storage->writeStream($path, $file->getStream()->detach());

        return ['url' => $url, 'name' => (string) $file->getClientFilename(), 'size' => (int) ($file->getSize() ?? 0)];
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @return string[]|null */
    private function tags(mixed $v): ?array
    {
        if (is_array($v)) {
            $tags = array_values(array_filter(array_map('trim', array_map('strval', $v))));
        } else {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $v))));
        }
        return $tags === [] ? null : $tags;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform administrator can manage the content library.', 403);
        }
        return null;
    }
}
