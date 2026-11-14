<?php

declare(strict_types=1);

namespace App\Application\Actions\Billing;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\Portal;
use App\Domain\Entity\BillingTransaction;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\Billing\PaystackClient;
use App\Service\Billing\SubscriptionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/billing, an organisation's administrator manages its subscription.
 *
 * Choosing a plan first produces an order (quote): what it is, the dates it
 * covers and the one total payable. Payment goes through Paystack, and nothing
 * is activated until the payment is verified. The rules themselves live in
 * SubscriptionService.
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
        private readonly \App\Service\PlatformSettings $settings,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /** GET /billing/subscription, where the account stands: plan, dates, state, users against limits. */
    public function current(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }

        // The content package the platform assigned to this school (type, price, term), if any.
        $package = $institution->getAssignedPackageId() !== null
            ? $this->em->getRepository(\App\Domain\Entity\ContentPackage::class)->find($institution->getAssignedPackageId())
            : null;

        return Json::write($response, $this->subscriptions->overview($institution) + [
            'paystack_live' => $this->paystack->isConfigured(),
            'content_package' => $package?->assignmentSummary($institution->getAssignedPackageAt()),
        ]);
    }

    /** POST /billing/quote {plan_id, interval?}, the order for a plan choice, before anything is paid or changed. */
    public function quote(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        $body = (array) $request->getParsedBody();
        $plan = $this->em->getRepository(SubscriptionPlan::class)->find((int) ($body['plan_id'] ?? 0));
        if ($plan === null) {
            return Json::error($response, 'Choose a plan.', 422);
        }
        $quote = $this->subscriptions->quote($institution, $plan, $this->interval($body));
        if (!$quote['ok']) {
            return Json::error($response, (string) $quote['error'], 422);
        }

        return Json::write($response, $quote);
    }

    /**
     * POST /billing/subscribe {plan_id, interval?}
     *
     * Places the order. When something is payable it starts a Paystack
     * transaction for the total; when nothing is (a change that takes effect at
     * the next renewal) it records the change and returns.
     */
    public function subscribe(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $plan = $this->em->getRepository(SubscriptionPlan::class)->find((int) ($body['plan_id'] ?? 0));
        if ($plan === null) {
            return Json::error($response, 'Choose a plan.', 422);
        }
        $quote = $this->subscriptions->quote($institution, $plan, $this->interval($body));
        if (!$quote['ok']) {
            return Json::error($response, (string) $quote['error'], 422);
        }

        // Nothing to pay: the change is noted and takes effect at the next renewal.
        if (!$quote['pay_now']) {
            $sub = $this->subscriptions->current($institution);
            if ($sub === null) {
                return Json::error($response, 'There is no subscription to change.', 422);
            }
            $sub->setPendingChange($plan, (string) $quote['interval']);
            $this->em->flush();
            $this->audit->log('billing.change_scheduled', $user, 'Subscription', (string) $sub->getId(), null, [
                'plan' => $plan->getCode(), 'interval' => $quote['interval'], 'effective_on' => $quote['effective_on'],
            ]);

            return Json::write($response, ['scheduled' => true, 'quote' => $quote, 'subscription' => $sub->toArray()], 201);
        }

        if (($unavailable = $this->paymentsUnavailable($response)) !== null) {
            return $unavailable;
        }

        $total = (int) $quote['total_kobo'];
        $reference = 'LEARNO-' . strtoupper(bin2hex(random_bytes(6)));
        $txn = new BillingTransaction($institution, $plan, $reference, $total);
        $txn->setInitiatedBy($user);
        $txn->setKind((string) $quote['kind']);
        $txn->setBillingInterval((string) $quote['interval']);
        $txn->setTaxKobo((int) $quote['tax_kobo']);
        $txn->setDetails([
            'lines' => $quote['lines'],
            'subtotal_kobo' => $quote['subtotal_kobo'],
            'tax_percent' => $quote['tax_percent'],
            'tax_kobo' => $quote['tax_kobo'],
            'total_kobo' => $total,
            'period_start' => $quote['period_start'],
            'period_end' => $quote['period_end'],
            'notes' => $quote['notes'],
        ]);
        $this->em->persist($txn);
        $this->em->flush();

        $authorizationUrl = null;
        if ($this->paystack->isConfigured()) {
            try {
                $data = $this->paystack->initializeTransaction(
                    $user->getEmail(),
                    $total,
                    $reference,
                    ['institution_id' => $institution->getId(), 'plan_code' => $plan->getCode(), 'kind' => $quote['kind'], 'interval' => $quote['interval']]
                );
                $authorizationUrl = $data['authorization_url'] ?? null;
            } catch (Throwable $e) {
                $txn->setStatus(BillingTransaction::FAILED);
                $this->em->flush();
                return Json::error($response, 'Could not start the payment: ' . $e->getMessage(), 502);
            }
        }
        $this->audit->log('billing.subscribe', $user, 'BillingTransaction', (string) $txn->getId(), null, [
            'plan' => $plan->getCode(), 'kind' => $quote['kind'], 'interval' => $quote['interval'], 'reference' => $reference, 'total_kobo' => $total,
        ]);

        return Json::write($response, [
            'reference' => $reference,
            'authorization_url' => $authorizationUrl,
            'amount_naira' => $total / 100,
            'quote' => $quote,
            // In dev (no Paystack key) the client shows a "Complete in test mode" button that calls /verify.
            'test_mode' => !$this->paystack->isConfigured(),
        ], 201);
    }

    /** POST /billing/cancel-renewal, stop future renewal. Access continues to the last paid day. */
    public function cancelRenewal(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        $sub = $this->subscriptions->current($institution);
        if ($sub === null || $sub->status() !== Subscription::ACTIVE) {
            return Json::error($response, 'There is no active subscription to cancel.', 422);
        }
        if ($sub->hasPaidAhead()) {
            return Json::error($response, sprintf('The next term is already paid for, to %s, so there is no renewal to stop before then. For a refund of a duplicate or incorrect charge, please contact support.', $sub->paidUntil()->format('j F Y')), 422);
        }
        $sub->stopRenewal();
        $this->em->flush();
        $this->audit->log('billing.renewal_stopped', $request->getAttribute('user'), 'Subscription', (string) $sub->getId(), null, ['access_until' => $sub->paidUntil()->format(DATE_ATOM)]);

        return Json::write($response, ['subscription' => $sub->toArray(), 'message' => sprintf(
            'Renewal is stopped. Access continues until %s. Your files and learning records are kept until %s.',
            $sub->paidUntil()->format('j F Y'),
            $sub->retentionUntil()->format('j F Y'),
        )]);
    }

    /** POST /billing/resume-renewal, undo a stopped renewal while the paid period is still running. */
    public function resumeRenewal(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        $sub = $this->subscriptions->current($institution);
        if ($sub === null || !$sub->isCancelAtPeriodEnd() || $sub->status() !== Subscription::ACTIVE) {
            return Json::error($response, 'There is no stopped renewal to resume. If the paid period has ended, renew from the plans below.', 422);
        }
        $sub->resumeRenewal();
        $this->em->flush();
        $this->audit->log('billing.renewal_resumed', $request->getAttribute('user'), 'Subscription', (string) $sub->getId(), null, null);

        return Json::write($response, ['subscription' => $sub->toArray()]);
    }

    /** POST /billing/clear-change, drop a plan change that was waiting for the next renewal. */
    public function clearChange(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        $sub = $this->subscriptions->current($institution);
        if ($sub === null || $sub->getPendingPlan() === null) {
            return Json::error($response, 'There is no change waiting.', 422);
        }
        $sub->setPendingChange(null, null);
        $this->em->flush();
        $this->audit->log('billing.change_cleared', $request->getAttribute('user'), 'Subscription', (string) $sub->getId(), null, null);

        return Json::write($response, ['subscription' => $sub->toArray()]);
    }

    /** POST /billing/verify, confirm a payment by { reference } and activate what was paid for. */
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
            return Json::write($response, ['transaction' => $txn->toArray(), 'subscription' => $this->subscriptions->current($txn->getInstitution())?->toArray()]);
        }
        if (($unavailable = $this->paymentsUnavailable($response)) !== null) {
            return $unavailable;
        }

        if ($this->paystack->isConfigured()) {
            try {
                $data = $this->paystack->verifyTransaction($reference);
            } catch (Throwable $e) {
                return Json::error($response, 'Verification failed: ' . $e->getMessage(), 502);
            }
        } else {
            // Dev-only test mode (paymentsUnavailable() refuses this outside APP_ENV=dev).
            $data = ['status' => 'success', 'amount' => $txn->getAmountKobo(), 'currency' => 'NGN', 'channel' => 'test', 'reference' => $reference];
        }

        $error = $this->complete($txn, $data, $request->getAttribute('user'));
        if ($error !== null) {
            return Json::error($response, $error, 402);
        }

        return Json::write($response, ['transaction' => $txn->toArray(), 'subscription' => $this->subscriptions->current($txn->getInstitution())?->toArray()]);
    }

    /**
     * POST /billing/webhook (public), Paystack's server-to-server notification.
     * Activates the subscription on charge.success even if the payer never comes
     * back to the app. The body is authenticated by its HMAC-SHA512 signature.
     */
    public function webhook(Request $request, Response $response): Response
    {
        $body = $request->getBody();
        $body->rewind();
        $raw = $body->getContents();
        if (!$this->paystack->isSignatureValid($raw, $request->getHeaderLine('x-paystack-signature'))) {
            return Json::error($response, 'Invalid signature.', 401);
        }

        $event = json_decode($raw, true);
        if (($event['event'] ?? '') === 'charge.success') {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $txn = $this->em->getRepository(BillingTransaction::class)->findOneBy(['reference' => (string) ($data['reference'] ?? '')]);
            if ($txn !== null && $txn->getStatus() !== BillingTransaction::SUCCESS) {
                $this->complete($txn, $data, null);
            }
        }

        // Always 200 for an authentic event so Paystack stops retrying.
        return Json::write($response, ['received' => true]);
    }

    /**
     * Mark a transaction paid and apply what it paid for, after checking the
     * gateway really reports a successful charge for at least the order total
     * in naira. Idempotent. Returns an error message, or null on success.
     */
    private function complete(BillingTransaction $txn, array $data, ?User $actor): ?string
    {
        if ($txn->getStatus() === BillingTransaction::SUCCESS) {
            return null;
        }
        $ok = ($data['status'] ?? '') === 'success'
            && (int) ($data['amount'] ?? 0) >= $txn->getAmountKobo()
            && strtoupper((string) ($data['currency'] ?? 'NGN')) === 'NGN';
        if (!$ok) {
            $txn->setStatus(BillingTransaction::FAILED);
            $this->em->flush();
            $this->audit->log('billing.failed', $actor, 'BillingTransaction', (string) $txn->getId(), null, [
                'reference' => $txn->getReference(), 'gateway_status' => $data['status'] ?? null,
                'amount' => $data['amount'] ?? null, 'expected' => $txn->getAmountKobo(),
            ]);
            return 'Payment was not completed for the full amount.';
        }

        $txn->setStatus(BillingTransaction::SUCCESS);
        $txn->setChannel((string) ($data['channel'] ?? 'card'));
        $txn->setPaidAt(new DateTimeImmutable());
        $sub = $this->subscriptions->apply($txn);

        $recipient = $actor ?? $txn->getInitiatedBy();
        if ($recipient !== null) {
            $plan = $txn->getPlan()->getName();
            $amount = '₦' . number_format($txn->getAmountKobo() / 100, $txn->getAmountKobo() % 100 === 0 ? 0 : 2);
            $this->notify->notify(
                $recipient,
                'billing',
                $txn->getKind() === 'upgrade' ? 'Payment received, upgraded to ' . $plan : 'Payment received, ' . $plan . ' plan',
                $txn->getKind() === 'upgrade'
                    ? sprintf('We received your payment of %s (receipt %s). The %s plan and its limits apply from now. Your term ends on %s.', $amount, $txn->getReference(), $plan, $sub->getPeriodEnd()->format('j M Y'))
                    : sprintf('We received your payment of %s (receipt %s). Your subscription is paid until %s.', $amount, $txn->getReference(), $sub->paidUntil()->format('j M Y')),
                Portal::link($recipient, '/management/billing'),
            );
        }
        $this->em->flush();
        $this->audit->log('billing.paid', $actor, 'BillingTransaction', (string) $txn->getId(), null, [
            'reference' => $txn->getReference(), 'channel' => $data['channel'] ?? null, 'kind' => $txn->getKind(), 'paid_until' => $sub->paidUntil()->format(DATE_ATOM),
        ]);

        return null;
    }

    /**
     * Payments can't be taken when the platform switched them off, or when no
     * Paystack key is set outside local development (which used to activate
     * subscriptions for free in production).
     */
    private function paymentsUnavailable(Response $response): ?Response
    {
        if (!$this->settings->bool('integrations.payments_enabled')) {
            return Json::error($response, 'Online payments are currently switched off. Please contact support.', 503);
        }
        $env = strtolower((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod'));
        if (!$this->paystack->isConfigured() && $env !== 'dev') {
            return Json::error($response, 'Payments are not configured yet. Please contact support.', 503);
        }
        return null;
    }

    /** GET /billing/transactions, the organisation's invoices and receipts. */
    public function transactions(Request $request, Response $response): Response
    {
        [$institution, $deny] = $this->scope($request, $response);
        if ($deny !== null) {
            return $deny;
        }
        $rows = $this->em->getRepository(BillingTransaction::class)->findBy(['institution' => $institution], ['createdAt' => 'DESC']);

        return Json::write($response, ['data' => array_map(static fn (BillingTransaction $t) => $t->toArray(), $rows), 'meta' => ['total' => count($rows)]]);
    }

    // --- helpers ---

    /** The billing period asked for, when one was. */
    private function interval(array $body): ?string
    {
        $v = trim((string) ($body['interval'] ?? ''));

        return $v === '' ? null : $v;
    }

    /**
     * The organisation the caller manages billing for, or the refusal.
     *
     * @return array{0: Institution|null, 1: Response|null}
     */
    private function scope(Request $request, Response $response): array
    {
        if (($guard = $this->adminGuard($request, $response)) !== null) {
            return [null, $guard];
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return [null, Json::error($response, 'No institution in scope.', 422)];
        }

        return [$institution, null];
    }

    private function adminGuard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->effectiveCode(), self::ADMIN, true)) {
            return Json::error($response, 'Only institution administrators can manage billing.', 403);
        }
        return null;
    }
}
