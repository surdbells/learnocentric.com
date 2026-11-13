<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Domain\LifecycleAware;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Shared behaviour for actions whose records move through the content
 * lifecycle (draft → review → approved → published → archived).
 *
 * The using class provides $em, $lifecycle (LifecycleService) and $notifier
 * (LifecycleNotifier), and the ResolvesInstitution trait for canActWithin().
 */
trait GovernedContent
{
    /**
     * A record the caller's school owns. Another school's id reads as "not
     * found", so its existence is not disclosed.
     *
     * @template T of object
     * @param class-string<T>                  $class
     * @param callable(T): (Institution|null)  $owner
     * @return T|null
     */
    protected function ownedRecord(Request $request, string $class, int $id, callable $owner): ?object
    {
        $record = $id > 0 ? $this->em->getRepository($class)->find($id) : null;

        return $record !== null && $this->canActWithin($request, $owner($record)) ? $record : null;
    }

    /**
     * What the caller may do with the item, and why not where they may not.
     *
     * @param string[] $blockers plain reasons it cannot be approved or published yet
     */
    protected function guidanceFor(Request $request, LifecycleAware $item, string $module, ?Institution $owner, string $noun, array $blockers = []): ?array
    {
        $viewer = $request->getAttribute('user');

        return $viewer instanceof User ? $this->lifecycle->guidance($item, $viewer, $module, $owner, $blockers, $noun) : null;
    }

    /** Why the caller cannot change the item right now, and what would unlock it. */
    protected function lockedReasonFor(Request $request, LifecycleAware $item, string $module, ?Institution $owner, string $noun): string
    {
        return $this->guidanceFor($request, $item, $module, $owner, $noun)['edit_reason']
            ?? sprintf('This %s cannot be changed right now.', $noun);
    }

    /**
     * Carry out the lifecycle move in the request body {to, note}: apply the
     * publication gate to any move that ends with learners seeing the item,
     * record it, and tell the people it concerns.
     *
     * @param callable(): string[] $blockers what stops the item being published, if anything
     * @return array{0: array<string, mixed>|null, 1: string|null} [result, error]
     */
    protected function moveItem(
        Request $request,
        LifecycleAware $item,
        string $noun,
        string $title,
        ?Institution $owner,
        string $path,
        ?callable $blockers = null,
    ): array {
        $body = (array) $request->getParsedBody();
        $to = (string) ($body['to'] ?? '');
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $from = $item->getLifecycleStatus();

        if ($blockers !== null && $this->lifecycle->leadsToPublication($from, $to, $actor)) {
            $reasons = $blockers();
            if ($reasons !== []) {
                return [null, $reasons[0]];
            }
        }

        try {
            $result = $this->lifecycle->transition($item, $to, $actor, isset($body['note']) ? (string) $body['note'] : null);
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
        $this->notifier->announce($item, $noun, $title, $owner, $from, (string) $result['status'], $actor, isset($body['note']) ? (string) $body['note'] : null, $path);
        $this->em->flush();

        return [$result, null];
    }
}
