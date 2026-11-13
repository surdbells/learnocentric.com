<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Drives content through the lifecycle state machine with role gating,
 * versioning (a ContentVersion per transition) and audit logging.
 *
 * It also answers, for one viewer, "what can I do with this item, and if I
 * cannot, why not?" (guidance()), so a screen never offers a step the API
 * would refuse and never leaves someone at a locked item without a way out.
 */
class LifecycleService
{
    /** Roles that may create/submit content. */
    private const AUTHORS = [Role::TEACHER, Role::ACADEMIC_LEAD, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::SUPER_ADMIN];
    /** Roles that may approve/return/publish/archive content. */
    private const APPROVERS = [Role::ACADEMIC_LEAD, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::SUPER_ADMIN];

    private const ACTION_ROLES = [
        'submit' => self::AUTHORS,
        'approve' => self::APPROVERS,
        'return' => self::APPROVERS,
        'recall' => self::APPROVERS,
        'publish' => self::APPROVERS,
        'archive' => self::APPROVERS,
        'takedown' => self::APPROVERS,
        'restore' => self::APPROVERS,
    ];

    /** Statuses in which the content itself may still be changed. */
    public const EDITABLE = [Lifecycle::DRAFT, Lifecycle::REVIEW];

    private const STATUS_LABEL = [
        Lifecycle::DRAFT => 'Draft',
        Lifecycle::REVIEW => 'In review',
        Lifecycle::APPROVED => 'Approved, not published',
        Lifecycle::PUBLISHED => 'Published',
        Lifecycle::ARCHIVED => 'Archived',
    ];

    private const APPROVER_NAMES = [
        Role::ACADEMIC_LEAD => 'an Academic Lead',
        Role::SCHOOL_ADMIN => 'the School Admin',
        Role::TUTOR_ADMIN => 'the Academy Admin',
    ];

    /** @var array<string, bool> */
    private array $allowCache = [];
    /** @var array<int|string, array{names: string, count: int}> */
    private array $approverCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly PermissionService $permissions,
        private readonly PlatformSettings $settings,
    ) {
    }

    public function canPerform(User $actor, string $action): bool
    {
        $allowed = self::ACTION_ROLES[$action] ?? [];

        return in_array($actor->getRole()->effectiveCode(), $allowed, true);
    }

    /**
     * Move an entity to a new status. Throws RuntimeException on an illegal
     * transition or insufficient permission.
     */
    public function transition(LifecycleAware $entity, string $to, User $actor, ?string $note = null): array
    {
        $from = $entity->getLifecycleStatus();

        if ($from === $to) {
            throw new RuntimeException("Item is already {$to}.");
        }
        if (!Lifecycle::isValid($to)) {
            throw new RuntimeException("Unknown status: {$to}.");
        }
        if (!Lifecycle::canTransition($from, $to)) {
            throw new RuntimeException("Cannot move from {$from} to {$to}.");
        }

        $action = Lifecycle::actionFor($from, $to);
        if (!$this->canPerform($actor, $action)) {
            throw new RuntimeException("You are not permitted to {$action} this item.");
        }

        $version = $this->record($entity, $from, $to, $action, $actor, $note);

        // The school's approval workflow (Settings → Data & System Preferences):
        //  admin_final  submit → approve → publish, each a separate step (default)
        //  single       approving also publishes
        //  none         submitting publishes straight away, with no review step
        $workflow = $this->workflowFor($actor);
        $auto = [];
        if ($workflow === 'none' && $action === 'submit') {
            $auto = [Lifecycle::APPROVED, Lifecycle::PUBLISHED];
        } elseif (in_array($workflow, ['single', 'none'], true) && $action === 'approve') {
            // Also clears items left in review from before a school dropped its review step.
            $auto = [Lifecycle::PUBLISHED];
        }
        foreach ($auto as $next) {
            $prev = $entity->getLifecycleStatus();
            $step = Lifecycle::actionFor($prev, $next);
            $why = $workflow === 'none'
                ? 'Automatic: this school\'s workflow needs no approval.'
                : 'Automatic: this school publishes on approval.';
            $version = $this->record($entity, $prev, $next, $step, $actor, $why);
            $to = $next;
            $action = $step;
        }

        return ['status' => $to, 'action' => $action, 'version' => $version, 'next' => Lifecycle::nextStates($to)];
    }

    /**
     * Would this move end with the item published for the actor's school?
     * (Approving or publishing always can; submitting does when there is no review step.)
     */
    public function leadsToPublication(string $from, string $to, User $actor): bool
    {
        if (!Lifecycle::canTransition($from, $to)) {
            return false;
        }
        $action = Lifecycle::actionFor($from, $to);

        return in_array($action, ['approve', 'publish'], true)
            || ($action === 'submit' && $this->workflowFor($actor) === 'none');
    }

    /**
     * What one viewer can do with an item, and why not where they cannot.
     *
     * @param string       $module   permission module that guards the item's routes (e.g. "assessment")
     * @param Institution|null $owner the school the item belongs to (null for platform content)
     * @param string[]     $blockers plain reasons the item cannot be approved or published yet
     * @param string       $noun     what to call the item in messages ("assessment")
     *
     * @return array{status: string, status_label: string, summary: string, workflow: string,
     *               can_edit: bool, edit_reason: ?string, blockers: string[],
     *               actions: array<int, array{to: string, action: string, label: string, allowed: bool, reason: ?string}>}
     */
    public function guidance(LifecycleAware $entity, User $viewer, string $module, ?Institution $owner, array $blockers = [], string $noun = 'item'): array
    {
        $status = $entity->getLifecycleStatus();
        $workflow = $this->workflowFor($viewer);
        $approvers = $this->approversOf($owner);
        $who = ucfirst($approvers['names']);

        $actions = [];
        $can = [];
        foreach (Lifecycle::nextStates($status) as $to) {
            $action = Lifecycle::actionFor($status, $to);
            // The same permission the route guard asks for on this move.
            $permission = match ($to) {
                Lifecycle::REVIEW => 'edit',
                Lifecycle::ARCHIVED => 'archive',
                default => 'approve',
            };
            $reason = null;
            if (!$this->canPerform($viewer, $action)) {
                $reason = sprintf('Only %s can %s.', $approvers['names'], $this->verb($action));
            } elseif (!$this->roleAllows($viewer, $module, $permission)) {
                $reason = sprintf(
                    'Your role (%s) does not include this step. The School Admin can change that under Roles & Permissions.',
                    $viewer->getRole()->getName()
                );
            } elseif ($blockers !== [] && (in_array($action, ['approve', 'publish'], true) || ($action === 'submit' && $workflow === 'none'))) {
                $reason = $blockers[0];
            }
            $can[$action] = $reason === null;
            $actions[] = [
                'to' => $to,
                'action' => $action,
                'label' => $this->label($action, $workflow),
                'allowed' => $reason === null,
                'reason' => $reason,
            ];
        }

        // --- May the viewer change the content itself? ---
        $editReason = null;
        if (!$this->roleAllows($viewer, $module, 'edit')) {
            $editReason = sprintf('Your role (%s) can view this %s but not change it.', $viewer->getRole()->getName(), $noun);
        } elseif (!in_array($status, self::EDITABLE, true)) {
            $editReason = match ($status) {
                Lifecycle::APPROVED => sprintf('This %s has been approved, so it is locked. Send it back to review to change it.', $noun)
                    . (($can['recall'] ?? false) ? '' : sprintf(' %s can do that.', $who)),
                Lifecycle::PUBLISHED => sprintf(
                    'This %s is published, so it is locked: learners may already be using it. To change it, archive it, restore it to draft, make the change, then approve and publish it again. Learners cannot use it while it is being revised.',
                    $noun
                ) . (($can['archive'] ?? false) ? '' : sprintf(' %s can do that.', $who)),
                default => sprintf('This %s is archived. Restore it to draft to change it.', $noun)
                    . (($can['restore'] ?? false) ? '' : sprintf(' %s can do that.', $who)),
            };
        }

        // --- One sentence on where the item stands and who it is waiting for ---
        $summary = match ($status) {
            Lifecycle::DRAFT => $workflow === 'none'
                ? 'Draft. Learners cannot see it until it is published.'
                : 'Draft. Submit it for review when it is ready; learners cannot see it yet.',
            Lifecycle::REVIEW => ($can['approve'] ?? false)
                ? 'Waiting for your decision: approve it or return it to the author.'
                : ($approvers['count'] === 0
                    ? sprintf('Waiting for approval, but this school has no active %s to approve it. The platform owner can approve it or add one.', $this->stripArticle($approvers['names']))
                    : (($can['approve'] ?? true) === false && $this->canPerform($viewer, 'approve') && $blockers !== []
                        ? 'In review. It cannot be approved yet: ' . lcfirst($blockers[0])
                        : sprintf('Waiting for approval by %s.', $approvers['names']))),
            Lifecycle::APPROVED => 'Approved but not published, so learners cannot see it yet. '
                . (($can['publish'] ?? false) ? 'Publish it to make it available.'
                    : ($this->canPerform($viewer, 'publish') && $blockers !== [] ? 'It cannot be published yet: ' . lcfirst($blockers[0]) : sprintf('%s can publish it.', $who))),
            Lifecycle::PUBLISHED => 'Published and available to learners.',
            default => 'Archived. Learners cannot see it.',
        };

        return [
            'status' => $status,
            'status_label' => self::STATUS_LABEL[$status] ?? ucfirst($status),
            'summary' => $summary,
            'workflow' => $workflow,
            'can_edit' => $editReason === null,
            'edit_reason' => $editReason,
            'blockers' => array_values($blockers),
            'actions' => $actions,
        ];
    }

    /**
     * The active people who can approve content for a school.
     *
     * @return array{names: string, count: int, users: User[]}
     */
    public function approversOf(?Institution $owner): array
    {
        if ($owner === null) {
            return ['names' => 'the platform owner', 'count' => 1, 'users' => []];
        }
        $key = (int) $owner->getId();
        if (!isset($this->approverCache[$key])) {
            $codes = [Role::ACADEMIC_LEAD, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN];
            /** @var User[] $users */
            $users = $this->em->createQueryBuilder()->select('u', 'r')
                ->from(User::class, 'u')->join('u.role', 'r')
                ->where('u.institution = :inst')->andWhere('u.status = :active')
                ->andWhere('(r.effectiveCode IN (:codes) OR r.code IN (:codes))')
                ->setParameter('inst', $owner)->setParameter('active', 'active')->setParameter('codes', $codes)
                ->getQuery()->getResult();
            $present = [];
            foreach ($users as $u) {
                $present[$u->getRole()->effectiveCode()] = true;
            }
            // Name the roles this school actually has; with none, name the ones it would need.
            $names = [];
            foreach (self::APPROVER_NAMES as $code => $name) {
                if (isset($present[$code])) {
                    $names[] = $name;
                }
            }
            if ($names === []) {
                $names = ['an Academic Lead', 'the School Admin'];
            }
            $this->approverCache[$key] = ['names' => implode(' or ', $names), 'count' => count($users), 'users' => $users];
        }

        return $this->approverCache[$key];
    }

    /** The person who last submitted the item for review, if recorded. */
    public function lastSubmitterId(LifecycleAware $entity): ?int
    {
        $row = $this->em->createQueryBuilder()->select('v.actorId AS actor')
            ->from(ContentVersion::class, 'v')
            ->where('v.entityType = :t')->andWhere('v.entityId = :id')->andWhere('v.action = :a')
            ->setParameter('t', $entity->lifecycleType())->setParameter('id', $entity->getId())->setParameter('a', 'submit')
            ->orderBy('v.version', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return isset($row['actor']) ? (int) $row['actor'] : null;
    }

    /** The route guard's decision for this user: the system floor, plus custom-role narrowing when it is switched on. */
    private function roleAllows(User $user, string $module, string $action): bool
    {
        $role = $user->getRole();
        if ($role->effectiveCode() === Role::SUPER_ADMIN) {
            return true;
        }
        $key = $role->getId() . '|' . $module . '|' . $action;
        if (!isset($this->allowCache[$key])) {
            $custom = !$role->isSystem();
            if ($custom && !$this->settings->bool('security.rbac_enforce')) {
                $role = $role->getBaseRole() ?? $role;
                $custom = !$role->isSystem();
            }
            $this->allowCache[$key] = $this->permissions->can($role, $module, $action)
                && (!$custom || $role->getBaseRole() === null || $this->permissions->can($role->getBaseRole(), $module, $action));
        }

        return $this->allowCache[$key];
    }

    private function label(string $action, string $workflow): string
    {
        return match ($action) {
            'submit' => $workflow === 'none' ? 'Publish' : 'Submit for review',
            'approve' => $workflow === 'admin_final' ? 'Approve' : 'Approve and publish',
            'return' => 'Return to author',
            'recall' => 'Send back to review',
            'publish' => 'Publish',
            'restore' => 'Restore to draft',
            default => 'Archive', // archive, takedown
        };
    }

    private function verb(string $action): string
    {
        return match ($action) {
            'submit' => 'submit it',
            'approve' => 'approve it',
            'return' => 'return it to the author',
            'recall' => 'send it back to review',
            'publish' => 'publish it',
            'restore' => 'restore it',
            default => 'archive it',
        };
    }

    /** "an Academic Lead or the School Admin" -> "Academic Lead or School Admin". */
    private function stripArticle(string $names): string
    {
        return (string) preg_replace('/\b(an|a|the) /i', '', $names);
    }

    /** Write one lifecycle step: version record, status change, audit entry. Returns the version number. */
    private function record(LifecycleAware $entity, string $from, string $to, string $action, User $actor, ?string $note): int
    {
        $version = $this->nextVersion($entity);
        $record = new ContentVersion($entity->lifecycleType(), (int) $entity->getId(), $version, $to, $action);
        $record->setFromStatus($from);
        $record->setSnapshot($entity->lifecycleSnapshot());
        $record->setNote($note);
        $record->setActor($actor->getId(), $actor->displayName());
        $this->em->persist($record);

        $entity->setLifecycleStatus($to);
        $this->em->flush();

        $this->audit->log(
            'content.' . $action,
            $actor,
            $entity->lifecycleType(),
            (string) $entity->getId(),
            ['status' => $from],
            ['status' => $to, 'note' => $note],
        );

        return $version;
    }

    /** The acting user's school approval workflow; platform users always use the full two-step review. */
    private function workflowFor(User $actor): string
    {
        $w = $actor->getInstitution()?->getSettings()['data']['approval_workflow'] ?? 'admin_final';

        return in_array($w, ['admin_final', 'single', 'none'], true) ? $w : 'admin_final';
    }

    /** @return ContentVersion[] newest first */
    public function history(LifecycleAware $entity): array
    {
        return $this->em->getRepository(ContentVersion::class)->findBy(
            ['entityType' => $entity->lifecycleType(), 'entityId' => $entity->getId()],
            ['version' => 'DESC'],
        );
    }

    private function nextVersion(LifecycleAware $entity): int
    {
        $count = (int) $this->em->getRepository(ContentVersion::class)->count([
            'entityType' => $entity->lifecycleType(),
            'entityId' => $entity->getId(),
        ]);

        return $count + 1;
    }
}
