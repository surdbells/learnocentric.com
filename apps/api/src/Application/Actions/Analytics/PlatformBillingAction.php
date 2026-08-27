<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Json;
use App\Domain\Entity\BillingTransaction;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platform billing depth for the super admin: recurring revenue, per-plan
 * breakdown, a revenue trend, upcoming renewals and payment issues, aggregated
 * across every institution's subscriptions and transactions. Read-only.
 */
final class PlatformBillingAction
{
    /** Renewals falling within this many days are surfaced as "upcoming". */
    private const RENEWAL_WINDOW_DAYS = 30;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /platform/billing/overview, headline revenue, plans, trend, renewals, issues. */
    public function overview(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $now = new DateTimeImmutable();

        /** @var Subscription[] $subs */
        $subs = $this->em->createQueryBuilder()->select('s', 'i', 'p')->from(Subscription::class, 's')
            ->join('s.institution', 'i')->join('s.plan', 'p')->getQuery()->getResult();
        /** @var BillingTransaction[] $txns */
        $txns = $this->em->createQueryBuilder()->select('t', 'i')->from(BillingTransaction::class, 't')
            ->join('t.institution', 'i')->getQuery()->getResult();

        $activeStatuses = [Subscription::ACTIVE, Subscription::GRACE];
        $mrr = 0;
        $payingInstitutions = [];
        $byPlan = [];
        $renewals = [];
        $graceOrExpired = [];

        foreach ($subs as $s) {
            $status = $s->status($now);
            $plan = $s->getPlan();
            $byPlan[$plan->getId()] ??= ['plan' => $plan->getName(), 'price_naira' => $plan->getPriceKobo() / 100, 'active' => 0, 'mrr' => 0];
            if (in_array($status, $activeStatuses, true)) {
                $mrr += $plan->getPriceKobo();
                $payingInstitutions[$s->getInstitution()->getId()] = true;
                $byPlan[$plan->getId()]['active']++;
                $byPlan[$plan->getId()]['mrr'] += $plan->getPriceKobo() / 100;

                $daysLeft = (int) floor(($s->getPeriodEnd()->getTimestamp() - $now->getTimestamp()) / 86400);
                if ($daysLeft <= self::RENEWAL_WINDOW_DAYS) {
                    $renewals[] = [
                        'institution' => $s->getInstitution()->getName(),
                        'plan' => $plan->getName(),
                        'period_end' => $s->getPeriodEnd()->format(DATE_ATOM),
                        'days_left' => $daysLeft,
                        'status' => $status,
                    ];
                }
            }
            if (in_array($status, [Subscription::GRACE, Subscription::EXPIRED], true)) {
                $graceOrExpired[] = [
                    'institution' => $s->getInstitution()->getName(),
                    'plan' => $plan->getName(),
                    'status' => $status,
                    'period_end' => $s->getPeriodEnd()->format(DATE_ATOM),
                ];
            }
        }
        usort($renewals, static fn ($a, $b) => $a['days_left'] <=> $b['days_left']);

        $mrrNaira = $mrr / 100;
        $collected = 0;
        $failed = [];
        foreach ($txns as $t) {
            if ($t->toArray()['status'] === BillingTransaction::SUCCESS) {
                $collected += $t->toArray()['amount_kobo'];
            } elseif ($t->toArray()['status'] === BillingTransaction::FAILED) {
                $failed[] = $t->toArray();
            }
        }

        return Json::write($response, [
            'stats' => [
                'mrr_naira' => $mrrNaira,
                'arr_naira' => $mrrNaira * 12,
                'active_subscriptions' => count($payingInstitutions),
                'paying_institutions' => count($payingInstitutions),
                'total_institutions' => (int) $this->em->getRepository(Institution::class)->count([]),
                'collected_naira' => $collected / 100,
                'failed_payments' => count($failed),
            ],
            'by_plan' => array_values($byPlan),
            'revenue_trend' => $this->revenueTrend($txns, $now),
            'renewals' => $renewals,
            'payment_issues' => [
                'subscriptions' => $graceOrExpired,
                'failed_transactions' => $failed,
            ],
        ]);
    }

    /** GET /platform/billing/invoices, every transaction, newest first, filterable by status. */
    public function invoices(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $params = $request->getQueryParams();
        $qb = $this->em->createQueryBuilder()->select('t', 'i')->from(BillingTransaction::class, 't')
            ->join('t.institution', 'i')->orderBy('t.createdAt', 'DESC');
        if (!empty($params['status'])) {
            $qb->andWhere('t.status = :st')->setParameter('st', (string) $params['status']);
        }
        if (!empty($params['institution_id'])) {
            $qb->andWhere('t.institution = :inst')->setParameter('inst', (int) $params['institution_id']);
        }

        $rows = array_map(static fn (BillingTransaction $t) => $t->toArray(), $qb->getQuery()->getResult());
        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** GET /platform/billing/subscriptions, every subscription with renewal info. */
    public function subscriptions(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $now = new DateTimeImmutable();
        /** @var Subscription[] $subs */
        $subs = $this->em->createQueryBuilder()->select('s', 'i', 'p')->from(Subscription::class, 's')
            ->join('s.institution', 'i')->join('s.plan', 'p')->orderBy('s.periodEnd', 'ASC')->getQuery()->getResult();

        $rows = array_map(static function (Subscription $s) use ($now) {
            return $s->toArray() + [
                'days_left' => (int) floor(($s->getPeriodEnd()->getTimestamp() - $now->getTimestamp()) / 86400),
                'price_naira' => $s->getPlan()->getPriceKobo() / 100,
            ];
        }, $subs);
        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    // --- helpers ---

    /**
     * Collected revenue (successful transactions) per month, last 6 months.
     *
     * @param BillingTransaction[] $txns
     * @return array<int, array{month:string, collected:float}>
     */
    private function revenueTrend(array $txns, DateTimeImmutable $now): array
    {
        $months = [];
        $order = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = $now->modify("first day of -$i month")->format('Y-m');
            $order[] = $key;
            $months[$key] = 0;
        }
        foreach ($txns as $t) {
            $arr = $t->toArray();
            if ($arr['status'] !== BillingTransaction::SUCCESS) {
                continue;
            }
            $when = $t->getPaidAt() ?? $t->getCreatedAt();
            $key = $when->format('Y-m');
            if (isset($months[$key])) {
                $months[$key] += $arr['amount_kobo'];
            }
        }
        return array_map(static fn (string $k) => ['month' => $k, 'collected' => $months[$k] / 100], $order);
    }

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Platform billing is restricted to the platform owner.', 403);
        }
        return null;
    }
}
