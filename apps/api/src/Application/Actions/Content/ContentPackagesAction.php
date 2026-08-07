<?php

declare(strict_types=1);

namespace App\Application\Actions\Content;

use App\Application\Support\Json;
use App\Domain\Entity\CatalogSubject;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/content/packages — curated content bundles (super admin only).
 * List / show (with resources) / create / delete; plus lifecycle transition/history.
 */
final class ContentPackagesAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
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

    /** POST /content/assign — assign a package to an institution (or unassign with package_id null). */
    public function assign(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $institution = $this->em->getRepository(Institution::class)->find((int) ($body['institution_id'] ?? 0));
        if ($institution === null) {
            return Json::error($response, 'Institution not found.', 404);
        }

        $packageId = $body['package_id'] ?? null;
        if ($packageId === null || $packageId === '' || (int) $packageId === 0) {
            $institution->setAssignedPackageId(null);
            $this->em->flush();
            $this->audit->log('content_package.unassign', $request->getAttribute('user'), 'Institution', (string) $institution->getId(), null, null);
            return Json::write($response, ['institution_id' => $institution->getId(), 'assigned_package_id' => null]);
        }

        $package = $this->em->getRepository(ContentPackage::class)->find((int) $packageId);
        if ($package === null) {
            return Json::error($response, 'Package not found.', 404);
        }
        $institution->setAssignedPackageId($package->getId());
        $this->em->flush();
        $this->audit->log('content_package.assign', $request->getAttribute('user'), 'Institution', (string) $institution->getId(), null, ['package_id' => $package->getId()]);

        return Json::write($response, [
            'institution_id' => $institution->getId(),
            'assigned_package_id' => $package->getId(),
            'assigned_package' => $package->getName(),
        ]);
    }

    /** GET /content/my-resources — the caller institution's assigned package + its resources. */
    public function myResources(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $user->getInstitution();

        $cleared = static fn (array $r): bool => $r['licence_status'] === ContentResource::APPROVED && ($r['visibility'] ?? 'published') === 'published';

        // Platform package resources assigned to the institution (if any).
        $packageInfo = null;
        $resources = [];
        $packageId = $institution?->getAssignedPackageId();
        if ($packageId !== null) {
            $package = $this->em->getRepository(ContentPackage::class)->find($packageId);
            if ($package !== null && $package->toArray()['isActive']) {
                $packageInfo = ['id' => $package->getId(), 'name' => $package->getName(), 'description' => $package->toArray()['description']];
                $resources = array_values(array_filter(
                    array_map(static fn (ContentResource $r) => $r->toArray(), $package->getResources()->getValues()),
                    $cleared,
                ));
            }
        }

        // School-uploaded resources scoped to the learner's institution.
        if ($institution !== null) {
            $school = array_values(array_filter(
                array_map(static fn (ContentResource $r) => $r->toArray(), $this->em->getRepository(ContentResource::class)->findBy(['institution' => $institution], ['id' => 'DESC'])),
                $cleared,
            ));
            $resources = array_merge($school, $resources);
        }

        return Json::write($response, [
            'package' => $packageInfo,
            'resources' => $resources,
        ]);
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
        if (!empty($body['catalog_subject_id'])) {
            $catalog = $this->em->getRepository(CatalogSubject::class)->find((int) $body['catalog_subject_id']);
            if ($catalog !== null) {
                $package->setCatalogSubject($catalog);
                if ($package->toArray()['subjectArea'] === null) {
                    $package->setSubjectArea($catalog->getName());
                }
            }
        }
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

    /** POST /content/packages/{id}/transition — body { to, note } */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $package = $this->em->getRepository(ContentPackage::class)->find((int) $args['id']);
        if ($package === null) {
            return Json::error($response, 'Package not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->lifecycle->transition($package, (string) ($body['to'] ?? ''), $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['package' => $package->toArray()] + $result);
    }

    /** GET /content/packages/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $package = $this->em->getRepository(ContentPackage::class)->find((int) $args['id']);
        if ($package === null) {
            return Json::error($response, 'Package not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($package)));
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
