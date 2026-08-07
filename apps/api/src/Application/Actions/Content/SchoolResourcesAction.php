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
 * /backend/content/school-resources — teacher/admin resource uploads scoped to
 * their institution. School-owned resources are served to that institution's
 * learners alongside package resources (ContentPackagesAction::myResources).
 */
final class SchoolResourcesAction
{
    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt',
        'mp4', 'webm', 'mov', 'mp3', 'm4a', 'wav',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageService $storage,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /content/school-resources — the caller institution's uploaded resources. */
    public function list(Request $request, Response $response): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->currentUser($request)->getInstitution();
        if ($institution === null) {
            return Json::write($response, ['data' => []]);
        }
        $rows = $this->em->getRepository(ContentResource::class)->findBy(['institution' => $institution], ['id' => 'DESC']);

        return Json::write($response, ['data' => array_map(static fn (ContentResource $r) => $r->toArray(), $rows)]);
    }

    /** POST /content/school-resources — upload a school resource (multipart: file + fields). */
    public function create(Request $request, Response $response): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $user = $this->currentUser($request);
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Json::error($response, 'A title is required.', 422);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'Attach a file to upload.', 422);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            return Json::error($response, 'That file is too large. The limit is 15 MB.', 413);
        }
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) {
            return Json::error($response, 'That file type is not allowed.', 422);
        }
        $path = 'school/' . date('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
        $this->storage->writeStream($path, $file->getStream()->detach());

        $resource = new ContentResource($title, (string) ($body['contentType'] ?? 'document'));
        $resource->setInstitution($institution);
        $resource->setCreatedBy($user);
        $resource->setSubjectArea($this->str($body['subjectArea'] ?? null));
        $resource->setGradeLevel($this->str($body['gradeLevel'] ?? null));
        $resource->setDescription($this->str($body['description'] ?? null));
        $resource->setDifficultyLevel($this->str($body['difficultyLevel'] ?? null));
        $resource->setTags($this->tags($body['tags'] ?? null));
        $resource->setFile($path, (string) $file->getClientFilename(), (int) ($file->getSize() ?? 0));
        $resource->setSource(trim($user->getFirstName() . ' ' . $user->getLastName()));
        $resource->setLicence('owned');
        $resource->setAudience((string) ($body['audience'] ?? 'learner'));
        $resource->setVisibility('published');
        $resource->setDownloadable(filter_var($body['downloadable'] ?? true, FILTER_VALIDATE_BOOL));
        $this->em->persist($resource);
        $this->em->flush();
        $this->audit->log('school_resource.upload', $user, 'ContentResource', (string) $resource->getId(), null, ['title' => $title]);

        return Json::write($response, $resource->toArray(), 201);
    }

    /** DELETE /content/school-resources?id= — remove one of the institution's resources. */
    public function delete(Request $request, Response $response): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->currentUser($request)->getInstitution();
        $resource = $this->em->getRepository(ContentResource::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($resource === null || $resource->getInstitution() === null || $institution === null
            || $resource->getInstitution()->getId() !== $institution->getId()) {
            return Json::error($response, 'Resource not found.', 404);
        }
        $id = $resource->getId();
        $this->em->remove($resource);
        $this->em->flush();
        $this->audit->log('school_resource.delete', $this->currentUser($request), 'ContentResource', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    private function staffGuard(Request $request, Response $response): ?Response
    {
        if (!in_array($this->currentUser($request)->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can upload resources.', 403);
        }
        return null;
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @return string[]|null */
    private function tags(mixed $v): ?array
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }
}
