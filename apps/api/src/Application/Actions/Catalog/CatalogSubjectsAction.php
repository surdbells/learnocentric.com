<?php

declare(strict_types=1);

namespace App\Application\Actions\Catalog;

use App\Application\Support\Json;
use App\Domain\Entity\CatalogSubject;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/catalog/subjects — the platform subject catalogue. Any authenticated
 * user may read it (schools pick from it, packages scope to it); only the super
 * admin may create, edit or retire catalogue subjects.
 */
final class CatalogSubjectsAction
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
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        // Super admin sees everything; everyone else only active subjects.
        $criteria = $this->isSuper($request) && (($request->getQueryParams()['all'] ?? '') === '1') ? [] : ['isActive' => true];
        $subjects = $this->em->getRepository(CatalogSubject::class)->findBy($criteria, ['name' => 'ASC']);

        return Json::write($response, array_map(static fn (CatalogSubject $s) => $s->toArray(), $subjects));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        if ($name === '' || $code === '') {
            return Json::error($response, 'A name and code are required.', 422);
        }
        if ($this->em->getRepository(CatalogSubject::class)->findOneBy(['code' => strtoupper($code)]) !== null) {
            return Json::error($response, 'A subject with that code already exists.', 409);
        }
        $subject = new CatalogSubject($name, $code);
        $this->apply($subject, $body);
        $this->em->persist($subject);
        $this->em->flush();
        $this->audit->log('catalog_subject.create', $request->getAttribute('user'), 'CatalogSubject', (string) $subject->getId(), null, $subject->toArray());

        return Json::write($response, $subject->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $subject = $this->em->getRepository(CatalogSubject::class)->find((int) ($body['id'] ?? 0));
        if ($subject === null) {
            return Json::error($response, 'Subject not found.', 404);
        }
        $before = $subject->toArray();
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $subject->setName((string) $body['name']);
        }
        $this->apply($subject, $body);
        $this->em->flush();
        $this->audit->log('catalog_subject.update', $request->getAttribute('user'), 'CatalogSubject', (string) $subject->getId(), $before, $subject->toArray());

        return Json::write($response, $subject->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $subject = $this->em->getRepository(CatalogSubject::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($subject === null) {
            return Json::error($response, 'Subject not found.', 404);
        }
        // Retire rather than hard-delete so adopted school subjects/packages keep their link.
        $subject->setIsActive(false);
        $this->em->flush();
        $this->audit->log('catalog_subject.retire', $request->getAttribute('user'), 'CatalogSubject', (string) $subject->getId(), null, null);

        return Json::write($response, ['retired' => true, 'id' => $subject->getId()]);
    }

    private function apply(CatalogSubject $s, array $body): void
    {
        if (array_key_exists('description', $body)) { $s->setDescription($body['description'] !== '' ? (string) $body['description'] : null); }
        if (array_key_exists('curriculum', $body)) { $s->setCurriculum($body['curriculum'] !== '' ? (string) $body['curriculum'] : null); }
        if (isset($body['is_active'])) { $s->setIsActive((bool) $body['is_active']); }
    }

    private function isSuper(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        return $user !== null && $user->getRole()->getCode() === 'super_admin';
    }

    private function guard(Request $request, Response $response): ?Response
    {
        if (!$this->isSuper($request)) {
            return Json::error($response, 'Only the platform administrator can manage the subject catalogue.', 403);
        }
        return null;
    }
}
