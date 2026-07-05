<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\AuditLog;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(
        string $actionType,
        ?User $actor = null,
        ?string $objectType = null,
        ?string $objectId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $ipDevice = null,
    ): void {
        $entry = new AuditLog($actionType);
        $entry->setUserId($actor?->getId());
        $entry->setInstitutionId($actor?->getInstitution()?->getId());
        $entry->setObject($objectType, $objectId);
        $entry->setOldValue($oldValue);
        $entry->setNewValue($newValue);
        $entry->setIpDevice($ipDevice);

        $this->em->persist($entry);
        $this->em->flush();
    }
}
