<?php

declare(strict_types=1);

namespace App\Domain;

/** A content entity that moves through the {@see Lifecycle} state machine. */
interface LifecycleAware
{
    public function getId(): ?int;

    public function getLifecycleStatus(): string;

    public function setLifecycleStatus(string $status): void;

    /** Short type label used in versions/audit, e.g. "Topic", "TopicDeliveryPack". */
    public function lifecycleType(): string;

    /** A serialisable snapshot stored with each version. */
    public function lifecycleSnapshot(): array;
}
