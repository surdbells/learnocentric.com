<?php

declare(strict_types=1);

namespace App\Application\Actions\Content;

use App\Application\Support\Json;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/content/packages — curated content bundles (super admin only).
 * List / show (with resources) / create / delete.
 */
final class ContentPackagesAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($response),
        };
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $package = $this->em->getRepository(ContentPackage::class)->find((int) $args['id']);
        if ($package === null) {
            return Json::error($response, 'Package not found.', 404);
        }
        return Json::write($response, $package->toArray(true));
    }

    private function list(Response $response): Response
    {
        $packages = $this->em->getRepository(ContentPackage::class)->findBy([], ['createdAt' => 'DESC']);
        return Json::write($response, array_map(static fn (ContentPackage $p) => $p->toArray(), $packages));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'A package name is required.', 422);
        }

        $package = new ContentPackage($name, (string) ($body['type'] ?? 'subject_pack'));
        $package->setDescription($this->str($body['description'] ?? null));
        $package->setSubjectArea($this->str($body['subjectArea'] ?? null));
        $package->setGradeLevel($this->str($body['gradeLevel'] ?? null));
        $package->setPriceKobo((int) round(((float) ($body['price'] ?? 0)) * 100));
        $package->setDurationMonths(isset($body['durationMonths']) && $body['durationMonths'] !== null ? (int) $body['durationMonths'] : null);
        $package->setIsActive((bool) ($body['isActive'] ?? true));

        foreach ($this->resolveResources($body['contentIds'] ?? []) as $resource) {
            $package->addResource($resource);
        }

        $this->em->persist($package);
        $this->em->flush();
        $this->audit->log('content_package.create', $request->getAttribute('user'), 'ContentPackage', (string) $package->getId(), null, $package->toArray());

        return Json::write($response, $package->toArray(true), 201);
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $package = $this->em->getRepository(ContentPackage::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($package === null) {
            return Json::error($response, 'Package not found.', 404);
        }
        $this->em->remove($package);
        $this->em->flush();
        $this->audit->log('content_package.delete', $request->getAttribute('user'), 'ContentPackage', (string) ($request->getQueryParams()['id'] ?? ''), null, null);

        return Json::write($response, ['deleted' => true]);
    }

    /** @param mixed $ids @return ContentResource[] */
    private function resolveResources(mixed $ids): array
    {
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $intIds = array_values(array_unique(array_map('intval', $ids)));
        return $this->em->getRepository(ContentResource::class)->findBy(['id' => $intIds]);
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform administrator can manage content packages.', 403);
        }
        return null;
    }
}
