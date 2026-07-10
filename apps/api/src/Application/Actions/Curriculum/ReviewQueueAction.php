<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\Question;
use App\Domain\Entity\SchemeOfWork;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Entity\Worksheet;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/curriculum/review-queue — content awaiting approval across every governed type. */
final class ReviewQueueAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);

        $items = array_merge(
            $this->topics($institution),
            $this->deliveryPacks($institution),
            $this->questions($institution),
            $this->assessments($institution),
            $this->worksheets($institution),
            $this->schemesOfWork($institution),
            $this->contentPackages($institution),
        );

        // Newest first across the merged set.
        usort($items, static fn (array $a, array $b): int => strcmp($b['updated_at'], $a['updated_at']));

        return Json::write($response, ['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function topics(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (Topic $t): array => [
            'type' => 'Topic',
            'id' => $t->getId(),
            'title' => $t->getTitle(),
            'subject' => $t->getSubject()->getName(),
            'status' => $t->getApprovalStatus(),
            'updated_at' => $t->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryPacks(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('p')->from(TopicDeliveryPack::class, 'p')
            ->join('p.topic', 't')->join('t.subject', 's')
            ->where('p.status = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (TopicDeliveryPack $p): array => [
            'type' => 'TopicDeliveryPack',
            'id' => $p->getId(),
            'title' => $p->getTopic()->getTitle(),
            'subject' => $p->getTopic()->getSubject()->getName(),
            'status' => $p->getStatus(),
            'updated_at' => $p->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function questions(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('q')->from(Question::class, 'q')
            ->join('q.topic', 't')->join('t.subject', 's')
            ->where('q.approvalStatus = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (Question $q): array => [
            'type' => 'Question',
            'id' => $q->getId(),
            'title' => mb_substr($q->getStem(), 0, 120),
            'subject' => $q->getTopic()->getSubject()->getName(),
            'status' => $q->getApprovalStatus(),
            'updated_at' => $q->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function assessments(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('a.approvalStatus = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (Assessment $a): array => [
            'type' => 'Assessment',
            'id' => $a->getId(),
            'title' => $a->getTitle(),
            'subject' => $a->getSubject()->getName(),
            'status' => $a->getApprovalStatus(),
            'updated_at' => $a->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function worksheets(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')
            ->join('w.topic', 't')->join('t.subject', 's')
            ->where('w.approvalStatus = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (Worksheet $w): array => [
            'type' => 'Worksheet',
            'id' => $w->getId(),
            'title' => $w->getTitle(),
            'subject' => $w->getTopic()->getSubject()->getName(),
            'status' => $w->getApprovalStatus(),
            'updated_at' => $w->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function schemesOfWork(?object $institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('sow')->from(SchemeOfWork::class, 'sow')->join('sow.subject', 's')
            ->where('sow.status = :st')->setParameter('st', Lifecycle::REVIEW);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        return array_map(static fn (SchemeOfWork $sow): array => [
            'type' => 'SchemeOfWork',
            'id' => $sow->getId(),
            'title' => $sow->toArray()['objective'] ?? ('Week ' . $sow->getWeekNumber()),
            'subject' => $sow->toArray()['subject'],
            'status' => $sow->getStatus(),
            'updated_at' => $sow->getUpdatedAt()->format(DATE_ATOM),
        ], $qb->getQuery()->getResult());
    }

    /**
     * Content packages are platform-level (super-admin authored, no institution),
     * so they only surface when the queue is not scoped to a single institution.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contentPackages(?object $institution): array
    {
        if ($institution !== null) {
            return [];
        }

        $packages = $this->em->getRepository(ContentPackage::class)->findBy(['status' => Lifecycle::REVIEW]);

        return array_map(static fn (ContentPackage $p): array => [
            'type' => 'ContentPackage',
            'id' => $p->getId(),
            'title' => $p->getName(),
            'subject' => $p->toArray()['subjectArea'],
            'status' => $p->getStatus(),
            'updated_at' => $p->getUpdatedAt()->format(DATE_ATOM),
        ], $packages);
    }
}
