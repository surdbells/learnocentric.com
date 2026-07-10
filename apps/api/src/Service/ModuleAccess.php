<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Institution;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves which gateable feature modules an institution may use, based on its
 * current subscription. Core features are never gated; only the optional
 * modules in SubscriptionPlan::MODULES are.
 *
 * Rules:
 *  - Platform super admins (no institution) get every module.
 *  - An institution with an active or in-grace subscription gets its plan's modules.
 *  - An institution whose subscription has expired gets no gated modules.
 *  - An institution that has never had a subscription is treated as a full-access
 *    trial (all modules) so onboarding is never blocked before a plan is assigned.
 */
final class ModuleAccess
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return string[] module keys granted to this user's scope right now. */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $institution = $user->getInstitution();
        if ($institution === null) {
            // Platform-level users (super admin) can reach everything.
            return SubscriptionPlan::MODULES;
        }
        return $this->forInstitution($institution);
    }

    /**
     * @return string[] module keys granted to an institution right now.
     *
     * DATA-RETENTION GUARANTEE (spec §19): expiry, lapse or suspension of a
     * subscription only withdraws access to gated feature MODULES — it NEVER
     * deletes or hides learner records, academic results or reports. An expired
     * institution simply returns an empty module set here; its underlying data
     * (users, attempts, worksheet/portfolio submissions, feedback, analytics)
     * stays intact and is restored the moment a plan is reactivated.
     *
     * There is deliberately no deletion path triggered by a lapse anywhere in
     * this service. If a scheduled data-purge is ever introduced, it MUST
     * exclude learner academic/report records so this guarantee still holds.
     */
    public function forInstitution(Institution $institution): array
    {
        $sub = $this->currentSubscription($institution);

        // Never subscribed → open trial. Assigned-but-expired → locked down.
        if ($sub === null) {
            return $this->everSubscribed($institution) ? [] : SubscriptionPlan::MODULES;
        }

        $status = $sub->status();
        if ($status === Subscription::ACTIVE || $status === Subscription::GRACE) {
            return $sub->getPlan()->getModules();
        }
        return [];
    }

    public function grants(?User $user, string $module): bool
    {
        return in_array($module, $this->forUser($user), true);
    }

    /** The institution's most recent non-cancelled subscription, if any. */
    private function currentSubscription(Institution $institution): ?Subscription
    {
        $subs = $this->em->getRepository(Subscription::class)->findBy(
            ['institution' => $institution, 'cancelled' => false],
            ['periodEnd' => 'DESC'],
            1,
        );
        return $subs[0] ?? null;
    }

    private function everSubscribed(Institution $institution): bool
    {
        return $this->em->getRepository(Subscription::class)->count(['institution' => $institution]) > 0;
    }
}
