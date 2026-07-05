<?php

declare(strict_types=1);

namespace App\Application\Actions\Billing;

use App\Application\Support\Json;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** /backend/billing/plans — plan catalogue (anyone authed reads; super admin manages). */
final class PlansAction
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
        $all = $this->isSuperAdmin($request) && (($request->getQueryParams()['all'] ?? '') === '1');
        $criteria = $all ? [] : ['isActive' => true];
        $plans = $this->em->getRepository(SubscriptionPlan::class)->findBy($criteria, ['priceKobo' => 'ASC']);

        return Json::write($response, array_map(static fn (SubscriptionPlan $p) => $p->toArray(), $plans));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($guard = $this->superGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        if ($code === '' || $name === '' || !isset($body['price_kobo'])) {
            return Json::error($response, 'A code, name and price_kobo are required.', 422);
        }
        if ($this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $code]) !== null) {
            return Json::error($response, 'A plan with that code already exists.', 409);
        }
        $plan = new SubscriptionPlan($code, $name, (int) $body['price_kobo']);
        $this->applyFields($plan, $body);
        $this->em->persist($plan);
        $this->em->flush();
        $this->audit->log('plan.create', $request->getAttribute('user'), 'SubscriptionPlan', (string) $plan->getId(), null, $plan->toArray());

        return Json::write($response, $plan->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        if (($guard = $this->superGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $plan = $this->em->getRepository(SubscriptionPlan::class)->find((int) ($body['id'] ?? 0));
        if ($plan === null) {
            return Json::error($response, 'Plan not found.', 404);
        }
        $before = $plan->toArray();
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $plan->setName((string) $body['name']);
        }
        if (isset($body['price_kobo'])) {
            $plan->setPriceKobo((int) $body['price_kobo']);
        }
        $this->applyFields($plan, $body);
        $this->em->flush();
        $this->audit->log('plan.update', $request->getAttribute('user'), 'SubscriptionPlan', (string) $plan->getId(), $before, $plan->toArray());

        return Json::write($response, $plan->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($guard = $this->superGuard($request, $response)) !== null) {
            return $guard;
        }
        $plan = $this->em->getRepository(SubscriptionPlan::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($plan === null) {
            return Json::error($response, 'Plan not found.', 404);
        }
        // Plans referenced by subscriptions are protected by the DB (RESTRICT); deactivate instead.
        $plan->setIsActive(false);
        $this->em->flush();
        $this->audit->log('plan.deactivate', $request->getAttribute('user'), 'SubscriptionPlan', (string) $plan->getId(), null, null);

        return Json::write($response, ['deactivated' => true, 'id' => $plan->getId()]);
    }

    private function applyFields(SubscriptionPlan $plan, array $body): void
    {
        if (array_key_exists('description', $body)) { $plan->setDescription($body['description'] !== '' ? (string) $body['description'] : null); }
        if (isset($body['interval'])) { $plan->setInterval((string) $body['interval']); }
        if (array_key_exists('max_students', $body)) { $plan->setMaxStudents($body['max_students'] !== null && $body['max_students'] !== '' ? (int) $body['max_students'] : null); }
        if (array_key_exists('max_teachers', $body)) { $plan->setMaxTeachers($body['max_teachers'] !== null && $body['max_teachers'] !== '' ? (int) $body['max_teachers'] : null); }
        if (array_key_exists('features', $body)) { $plan->setFeatures(is_array($body['features']) ? $body['features'] : null); }
        if (isset($body['is_active'])) { $plan->setIsActive((bool) $body['is_active']); }
    }

    private function isSuperAdmin(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        return $user !== null && $user->getRole()->getCode() === 'super_admin';
    }

    private function superGuard(Request $request, Response $response): ?Response
    {
        if (!$this->isSuperAdmin($request)) {
            return Json::error($response, 'Only the platform administrator can manage plans.', 403);
        }
        return null;
    }
}
