<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Drives content through the lifecycle state machine with role gating,
 * versioning (a ContentVersion per transition) and audit logging.
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function canPerform(User $actor, string $action): bool
    {
        $allowed = self::ACTION_ROLES[$action] ?? [];

        return in_array($actor->getRole()->getCode(), $allowed, true);
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

        $version = $this->nextVersion($entity);
        $record = new ContentVersion($entity->lifecycleType(), (int) $entity->getId(), $version, $to, $action);
        $record->setFromStatus($from);
        $record->setSnapshot($entity->lifecycleSnapshot());
        $record->setNote($note);
        $record->setActor($actor->getId(), trim($actor->getFirstName() . ' ' . $actor->getLastName()));
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

        return ['status' => $to, 'action' => $action, 'version' => $version, 'next' => Lifecycle::nextStates($to)];
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
