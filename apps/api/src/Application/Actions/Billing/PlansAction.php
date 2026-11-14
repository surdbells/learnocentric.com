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

/** /backend/billing/plans, plan catalogue (anyone authed reads; super admin manages). */
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
        // An organisation is offered the plans meant for it (school or academy).
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $institution = $user?->getInstitution();
        if (!$all && $institution !== null) {
            $criteria['audience'] = $institution->getType() === 'academy' ? 'academy' : 'school';
        }
        $plans = $this->em->getRepository(SubscriptionPlan::class)->findBy($criteria, ['audience' => 'DESC', 'sortOrder' => 'ASC', 'priceKobo' => 'ASC']);
        // The plan an organisation is already on stays listed so it can be renewed,
        // even when it is no longer on sale or was sold to the other kind of organisation.
        if (!$all && $institution !== null) {
            $current = $this->em->getRepository(\App\Domain\Entity\Subscription::class)->findOneBy(['institution' => $institution], ['id' => 'DESC']);
            if ($current !== null && !in_array($current->getPlan(), $plans, true)) {
                array_unshift($plans, $current->getPlan());
            }
        }

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
        if ($code === '' || $name === '' || (!isset($body['price_kobo']) && empty($body['is_custom']))) {
            return Json::error($response, 'A code, name and price are required (a custom-quote plan needs no price).', 422);
        }
        if ($this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $code]) !== null) {
            return Json::error($response, 'A plan with that code already exists.', 409);
        }
        $plan = new SubscriptionPlan($code, $name, (int) ($body['price_kobo'] ?? 0));
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
        if (isset($body['audience'])) { $plan->setAudience((string) $body['audience']); }
        $intOrNull = static fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;
        if (array_key_exists('annual_price_kobo', $body)) { $plan->setAnnualPriceKobo($intOrNull($body['annual_price_kobo'])); }
        if (array_key_exists('max_admins', $body)) { $plan->setMaxAdmins($intOrNull($body['max_admins'])); }
        if (array_key_exists('storage_gb', $body)) { $plan->setStorageGb($intOrNull($body['storage_gb'])); }
        if (array_key_exists('period_days', $body)) { $plan->setPeriodDays($intOrNull($body['period_days'])); }
        if (array_key_exists('is_custom', $body)) { $plan->setIsCustom((bool) $body['is_custom']); }
        if (array_key_exists('sort_order', $body)) { $plan->setSortOrder((int) $body['sort_order']); }
        if (array_key_exists('max_students', $body)) { $plan->setMaxStudents($body['max_students'] !== null && $body['max_students'] !== '' ? (int) $body['max_students'] : null); }
        if (array_key_exists('max_teachers', $body)) { $plan->setMaxTeachers($body['max_teachers'] !== null && $body['max_teachers'] !== '' ? (int) $body['max_teachers'] : null); }
        if (array_key_exists('features', $body)) { $plan->setFeatures(is_array($body['features']) ? $body['features'] : null); }
        if (array_key_exists('modules', $body)) { $plan->setModules(is_array($body['modules']) ? $body['modules'] : null); }
        if (isset($body['is_active'])) { $plan->setIsActive((bool) $body['is_active']); }
    }

    private function isSuperAdmin(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        return $user !== null && $user->getRole()->effectiveCode() === 'super_admin';
    }

    private function superGuard(Request $request, Response $response): ?Response
    {
        if (!$this->isSuperAdmin($request)) {
            return Json::error($response, 'Only the platform administrator can manage plans.', 403);
        }
        return null;
    }
}
