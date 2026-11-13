<?php

declare(strict_types=1);

namespace App\Application\Actions\Content;

use App\Application\Support\Json;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\ResourceProgress;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The signed-in user's own progress on a resource they can see.
 *  GET /content/my-resources/{id}/progress
 *  PUT /content/my-resources/{id}/progress  {done?, notes?}
 */
final class ResourceProgressAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $resource = $this->em->getRepository(ContentResource::class)->find((int) ($args['id'] ?? 0));
        if ($resource === null || !$this->canSee($user, $resource)) {
            return Json::error($response, 'Resource not found.', 404);
        }
        $repo = $this->em->getRepository(ResourceProgress::class);
        $progress = $repo->findOneBy(['user' => $user, 'resource' => $resource]);

        if (strtoupper($request->getMethod()) === 'GET') {
            return Json::write($response, $progress?->toArray() ?? ['resource_id' => $resource->getId(), 'done' => false, 'notes' => '', 'updated_at' => null]);
        }

        $body = (array) $request->getParsedBody();
        if ($progress === null) {
            $progress = new ResourceProgress($user, $resource);
            $this->em->persist($progress);
        }
        if (array_key_exists('done', $body)) {
            $progress->setDone(filter_var($body['done'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('notes', $body)) {
            $progress->setNotes($body['notes'] === null ? null : (string) $body['notes']);
        }
        $this->em->flush();

        return Json::write($response, $progress->toArray());
    }

    /** Their own school's upload, or a resource in the package assigned to their school. */
    private function canSee(User $user, ContentResource $resource): bool
    {
        $institution = $user->getInstitution();
        if ($institution === null) {
            return true; // platform owner
        }
        if ($resource->getInstitution()?->getId() === $institution->getId()) {
            return true;
        }
        $packageId = $institution->getAssignedPackageId();
        $package = $packageId !== null ? $this->em->getRepository(ContentPackage::class)->find($packageId) : null;

        return $package !== null && $package->getResources()->contains($resource);
    }
}
