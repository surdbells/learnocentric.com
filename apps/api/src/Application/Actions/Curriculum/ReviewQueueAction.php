<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Question;
use App\Domain\Entity\SchemeOfWork;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/curriculum/review-queue, content waiting on an approver across
 * every governed type.
 *
 * Two kinds of item are listed: those in review (waiting for a decision) and
 * those approved but not yet published. At a school with a separate publish
 * step the second kind would otherwise drop out of sight, approved but never
 * available to learners.
 *
 * Each row carries "lifecycle": what this viewer may do with it and, where they
 * may not, why (see LifecycleService::guidance()).
 */
final class ReviewQueueAction
{
    use ResolvesInstitution;

    private const WAITING = [Lifecycle::REVIEW, Lifecycle::APPROVED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LifecycleService $lifecycle,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        $viewer = $request->getAttribute('user');
        $viewer = $viewer instanceof User ? $viewer : null;

        $items = array_merge(
            $this->topics($institution, $viewer),
            $this->deliveryPacks($institution, $viewer),
            $this->questions($institution, $viewer),
            $this->assessments($institution, $viewer),
            $this->worksheets($institution, $viewer),
            $this->schemesOfWork($institution, $viewer),
            $this->contentPackages($institution, $viewer),
        );

        // Newest first across the merged set.
        usort($items, static fn (array $a, array $b): int => strcmp($b['updated_at'], $a['updated_at']));

        return Json::write($response, ['data' => $items, 'meta' => [
            'total' => count($items),
            'in_review' => count(array_filter($items, static fn (array $i): bool => $i['status'] === Lifecycle::REVIEW)),
            'to_publish' => count(array_filter($items, static fn (array $i): bool => $i['status'] === Lifecycle::APPROVED)),
        ]]);
    }

    /**
     * One queue row.
     *
     * @param string[] $blockers what stops the item being approved or published
     * @return array<string, mixed>
     */
    private function item(
        string $type,
        LifecycleAware $entity,
        string $title,
        ?string $subject,
        \DateTimeInterface $updatedAt,
        ?User $viewer,
        string $module,
        ?Institution $owner,
        string $noun,
        array $blockers = [],
    ): array {
        return [
            'type' => $type,
            'id' => $entity->getId(),
            'title' => $title,
            'subject' => $subject,
            'status' => $entity->getLifecycleStatus(),
            'updated_at' => $updatedAt->format(DATE_ATOM),
            'lifecycle' => $viewer === null ? null : $this->lifecycle->guidance($entity, $viewer, $module, $owner, $blockers, $noun),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function topics(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(fn (Topic $t): array => $this->item(
            'Topic', $t, $t->getTitle(), $t->getSubject()->getName(), $t->getUpdatedAt(),
            $viewer, 'curriculum_pack', $t->getSubject()->getInstitution(), 'topic',
        ), $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryPacks(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('p')->from(TopicDeliveryPack::class, 'p')
            ->join('p.topic', 't')->join('t.subject', 's')
            ->where('p.status IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(fn (TopicDeliveryPack $p): array => $this->item(
            'TopicDeliveryPack', $p, $p->getTopic()->getTitle(), $p->getTopic()->getSubject()->getName(), $p->getUpdatedAt(),
            $viewer, 'delivery_pack', $p->getTopic()->getSubject()->getInstitution(), 'lesson pack',
        ), $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function questions(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('q')->from(Question::class, 'q')
            ->join('q.topic', 't')->join('t.subject', 's')
            ->where('q.approvalStatus IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(fn (Question $q): array => $this->item(
            'Question', $q, mb_substr($q->getStem(), 0, 120), $q->getTopic()->getSubject()->getName(), $q->getUpdatedAt(),
            $viewer, 'assessment', $q->getTopic()->getSubject()->getInstitution(), 'question', $q->publishBlockers(),
        ), $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function assessments(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('a.approvalStatus IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(fn (Assessment $a): array => $this->item(
            'Assessment', $a, $a->getTitle(), $a->getSubject()->getName(), $a->getUpdatedAt(),
            $viewer, 'assessment', $a->getSubject()->getInstitution(), 'assessment', $a->publishBlockers(),
        ), $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function worksheets(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')
            ->join('w.topic', 't')->join('t.subject', 's')
            ->where('w.approvalStatus IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(fn (Worksheet $w): array => $this->item(
            'Worksheet', $w, $w->getTitle(), $w->getTopic()->getSubject()->getName(), $w->getUpdatedAt(),
            $viewer, 'worksheet', $w->getTopic()->getSubject()->getInstitution(), 'worksheet',
        ), $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function schemesOfWork(?object $institution, ?User $viewer): array
    {
        $qb = $this->em->createQueryBuilder()->select('sow')->from(SchemeOfWork::class, 'sow')->join('sow.subject', 's')
            ->where('sow.status IN (:st)')->setParameter('st', self::WAITING);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(function (SchemeOfWork $sow) use ($viewer): array {
            $arr = $sow->toArray();

            return $this->item(
                'SchemeOfWork', $sow, (string) ($arr['objective'] ?? ('Week ' . $sow->getWeekNumber())), $arr['subject'] ?? null, $sow->getUpdatedAt(),
                $viewer, 'curriculum_pack', $sow->getSchoolClass()->getInstitution(), 'scheme entry',
            );
        }, $qb->getQuery()->getResult());
    }

    /**
     * Content packages are platform-level (super-admin authored, no institution),
     * so they only surface when the queue is not scoped to a single institution.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contentPackages(?object $institution, ?User $viewer): array
    {
        if ($institution !== null) {
            return [];
        }

        $packages = $this->em->getRepository(ContentPackage::class)->findBy(['status' => self::WAITING]);

        return array_map(fn (ContentPackage $p): array => $this->item(
            'ContentPackage', $p, $p->getName(), $p->toArray()['subjectArea'] ?? null, $p->getUpdatedAt(),
            $viewer, 'resource', null, 'content package',
        ), $packages);
    }
}
