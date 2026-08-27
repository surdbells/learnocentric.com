<?php

declare(strict_types=1);

namespace App\Application\Actions\Billing;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\BillingTransaction;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\Billing\PaystackClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/billing, an institution admin subscribes to a plan (Paystack
 * checkout), the payment is verified, and access is granted with a grace
 * window after each period (spec §17).
 */
final class BillingAction
{
    use ResolvesInstitution;

    private const ADMIN = ['school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly PaystackClient $paystack,
        private readonly \App\Service\NotificationService $notify,
    ) {
    }

    /** GET /billing/subscription, the institution's current subscription + access state. */
    public function current(Request $request, Response $response): Response
    {
        if (($guard = $this->adminGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution in scope.', 422);
        }
        $sub = $this->currentSubscription($institution);
        $status = $sub?->status() ?? 'none';

        return Json::write($response, [
            'subscription' => $sub?->toArray(),
            'has_access' => in_array($status, [Subscription::ACTIVE, Subscription::GRACE], true),
            'paystack_live' => $this->paystack->isConfigured(),
        ]);
    }

    /** POST /billing/subscribe, start a Paystack transaction for { plan_id }. */
    public function subscribe(Request $request, Response $response): Response
    {
        if (($guard = $this->adminGuard($request, $response)) !== null) {
            return $guard;
        }
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution in scope.', 422);
        }
        $body = (array) $request->getParsedBody();
        $plan = $this->em->getRepository(SubscriptionPlan::class)->find((int) ($body['plan_id'] ?? 0));
        if ($plan === null || !$plan->isActive()) {
            return Json::error($response, 'Choose an active plan.', 422);
        }

        $reference = 'LEARNO-' . strtoupper(bin2hex(random_bytes(6)));
        $txn = new BillingTransaction($institution, $plan, $reference, $plan->getPriceKobo());
        $txn->setInitiatedBy($user);
        $this->em->persist($txn);
        $this->em->flush();

        $authorizationUrl = null;
        if ($this->paystack->isConfigured()) {
            try {
                $data = $this->paystack->initializeTransaction(
                    $user->getEmail(),
                    $plan->getPriceKobo(),
                    $reference,
                    ['institution_id' => $institution->getId(), 'plan_code' => $plan->getCode()]
                );
                $authorizationUrl = $data['authorization_url'] ?? null;
            } catch (Throwable $e) {
                $txn->setStatus(BillingTransaction::FAILED);
                $this->em->flush();
                return Json::error($response, 'Could not start the payment: ' . $e->getMessage(), 502);
            }
        }
        $this->audit->log('billing.subscribe', $user, 'BillingTransaction', (string) $txn->getId(), null, ['plan' => $plan->getCode(), 'reference' => $reference]);

        return Json::write($response, [
            'reference' => $reference,
            'authorization_url' => $authorizationUrl,
            'amount_naira' => $plan->getPriceKobo() / 100,
            // In dev (no Paystack key) the client shows a "Complete in test mode" button that calls /verify.
            'test_mode' => !$this->paystack->isConfigured(),
        ], 201);
    }

    /** POST /billing/verify, confirm a payment by { reference } and activate the subscription. */
    public function verify(Request $request, Response $response): Response
    {
        if (($guard = $this->adminGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $reference = (string) (($request->getParsedBody()['reference'] ?? '') ?: ($request->getQueryParams()['reference'] ?? ''));
        $txn = $this->em->getRepository(BillingTransaction::class)->findOneBy(['reference' => $reference]);
        if ($txn === null) {
            return Json::error($response, 'Transaction not found.', 404);
        }
        if ($institution !== null && $txn->getInstitution()->getId() !== $institution->getId()) {
            return Json::error($response, 'That transaction is not for your institution.', 403);
        }
        if ($txn->getStatus() === BillingTransaction::SUCCESS) {
            return Json::write($response, ['transaction' => $txn->toArray(), 'subscription' => $this->currentSubscription($txn->getInstitution())?->toArray()]);
        }

        $paid = false;
        $channel = 'test';
        if ($this->paystack->isConfigured()) {
            try {
                $data = $this->paystack->verifyTransaction($reference);
                $paid = ($data['status'] ?? '') === 'success';
                $channel = $data['channel'] ?? 'card';
            } catch (Throwable $e) {
                return Json::error($response, 'Verification failed: ' . $e->getMessage(), 502);
            }
        } else {
            // Test mode: no gateway, so treat the confirm click as a successful payment.
            $paid = true;
        }

        if (!$paid) {
            $txn->setStatus(BillingTransaction::FAILED);
            $this->em->flush();
            return Json::error($response, 'Payment was not completed.', 402);
        }

        $txn->setStatus(BillingTransaction::SUCCESS);
        $txn->setChannel($channel);
        $txn->setPaidAt(new DateTimeImmutable());
        $sub = $this->activate($txn->getInstitution(), $txn->getPlan());
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $this->notify->notify(
            $actor,
            'billing',
            'Payment received, ' . $txn->getPlan()->getName() . ' plan',
            'We received your payment of ₦' . number_format($txn->getAmountKobo() / 100) . '. Your subscription is active until ' . $sub->getPeriodEnd()->format('j M Y') . '.',
            '/admin/management/billing',
        );
        $this->em->flush();
        $this->audit->log('billing.paid', $request->getAttribute('user'), 'BillingTransaction', (string) $txn->getId(), null, ['reference' => $reference, 'channel' => $channel]);

        return Json::write($response, ['transaction' => $txn->toArray(), 'subscription' => $sub->toArray()]);
    }

    /** GET /billing/transactions, invoice history for the institution. */
    public function transactions(Request $request, Response $response): Response
    {
        if (($guard = $this->adminGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution in scope.', 422);
        }
        $rows = $this->em->getRepository(BillingTransaction::class)->findBy(['institution' => $institution], ['createdAt' => 'DESC']);

        return Json::write($response, ['data' => array_map(static fn (BillingTransaction $t) => $t->toArray(), $rows), 'meta' => ['total' => count($rows)]]);
    }

    // --- helpers ---

    private function currentSubscription(Institution $institution): ?Subscription
    {
        return $this->em->getRepository(Subscription::class)->findOneBy(['institution' => $institution], ['id' => 'DESC']);
    }

    /** Grant or extend the institution's subscription for one paid period. */
    private function activate(Institution $institution, SubscriptionPlan $plan): Subscription
    {
        $now = new DateTimeImmutable();
        $sub = $this->currentSubscription($institution);

        if ($sub === null) {
            $sub = new Subscription($institution, $plan, $now, $now->add($plan->periodInterval()));
            $this->em->persist($sub);
            return $sub;
        }

        $sub->setPlan($plan);
        $sub->setCancelled(false);
        if ($sub->getPeriodEnd() > $now) {
            // still within the paid window (or renewing early) → stack onto the remaining time
            $sub->setPeriodEnd($sub->getPeriodEnd()->add($plan->periodInterval()));
        } else {
            // lapsed → start a fresh period from today
            $sub->setPeriodStart($now);
            $sub->setPeriodEnd($now->add($plan->periodInterval()));
        }
        return $sub;
    }

    private function adminGuard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::ADMIN, true)) {
            return Json::error($response, 'Only institution administrators can manage billing.', 403);
        }
        return null;
    }
}
