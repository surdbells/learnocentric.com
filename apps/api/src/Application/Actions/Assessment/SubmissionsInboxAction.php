<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Support\Json;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\User;
use App\Domain\Entity\WorksheetSubmission;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /assessment/submissions/inbox — a unified grading inbox. It gathers every
 * worksheet and portfolio submission awaiting manual review (scoped to the
 * teacher's assigned subjects, or the whole institution for an admin) with
 * headline counts, so grading happens in one place instead of hunting across
 * the worksheet and portfolio pages.
 */
final class SubmissionsInboxAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $subjectIds = $this->subjectScope($user);

        $items = [];
        $now = new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $overdue = 0;

        if ($subjectIds !== []) {
            $ws = $this->em->createQueryBuilder()->select('ws', 'w', 't', 'st')->from(WorksheetSubmission::class, 'ws')
                ->join('ws.worksheet', 'w')->join('w.topic', 't')->join('ws.student', 'st')
                ->where('ws.status = :st')->andWhere('t.subject IN (:subs)')
                ->setParameter('st', WorksheetSubmission::SUBMITTED)->setParameter('subs', $subjectIds)
                ->orderBy('ws.id', 'DESC')->getQuery()->getResult();
            foreach ($ws as $s) {
                /** @var WorksheetSubmission $s */
                $submittedAt = $s->getSubmittedAt() ?? $s->getCreatedAt();
                $due = $s->getWorksheet()->getDueDate();
                $isOverdue = $due !== null && $due < $now;
                if ($isOverdue) {
                    $overdue++;
                }
                $items[] = [
                    'id' => $s->getId(),
                    'type' => 'worksheet',
                    'type_label' => 'Worksheet',
                    'learner' => $s->getStudent()->getFirstName() . ' ' . $s->getStudent()->getLastName(),
                    'title' => $s->getWorksheet()->getTitle(),
                    'topic' => $s->getWorksheet()->getTopic()->getTitle(),
                    'subject' => $s->getWorksheet()->getTopic()->getSubject()->getName(),
                    'submitted_at' => $submittedAt?->format(DATE_ATOM),
                    'scoring_method' => 'Teacher review',
                    'overdue' => $isOverdue,
                ];
            }

            $pf = $this->em->createQueryBuilder()->select('p', 't', 'st')->from(PortfolioEntry::class, 'p')
                ->join('p.topic', 't')->join('p.student', 'st')
                ->where('p.status = :st')->andWhere('t.subject IN (:subs)')
                ->setParameter('st', PortfolioEntry::SUBMITTED)->setParameter('subs', $subjectIds)
                ->orderBy('p.id', 'DESC')->getQuery()->getResult();
            foreach ($pf as $p) {
                /** @var PortfolioEntry $p */
                $items[] = [
                    'id' => $p->getId(),
                    'type' => 'portfolio',
                    'type_label' => 'Portfolio task',
                    'learner' => $p->getStudent()->getFirstName() . ' ' . $p->getStudent()->getLastName(),
                    'title' => $p->getTitle(),
                    'topic' => $p->getTopic()->getTitle(),
                    'subject' => $p->getTopic()->getSubject()->getName(),
                    'submitted_at' => $p->getSubmittedAt()?->format(DATE_ATOM),
                    'scoring_method' => 'Rubric review',
                    'overdue' => false,
                ];
            }
        }

        usort($items, static fn ($a, $b) => strcmp((string) $b['submitted_at'], (string) $a['submitted_at']));

        $worksheetCount = count(array_filter($items, static fn ($i) => $i['type'] === 'worksheet'));
        $portfolioCount = count(array_filter($items, static fn ($i) => $i['type'] === 'portfolio'));
        $submittedToday = count(array_filter($items, static fn ($i) => str_starts_with((string) $i['submitted_at'], $today)));

        return Json::write($response, [
            'kpis' => [
                'pending_review' => count($items),
                'submitted_today' => $submittedToday,
                'worksheets_to_grade' => $worksheetCount,
                'portfolio_to_review' => $portfolioCount,
                'overdue' => $overdue,
            ],
            'breakdown' => [
                ['label' => 'Worksheets', 'value' => $worksheetCount, 'tone' => 'primary'],
                ['label' => 'Portfolio tasks', 'value' => $portfolioCount, 'tone' => 'info'],
            ],
            'items' => $items,
        ]);
    }

    /**
     * Subjects the viewer grades: a teacher's assigned subjects, or every subject
     * in an admin's institution.
     *
     * @return int[]
     */
    private function subjectScope(User $user): array
    {
        $role = $user->getRole()->getCode();
        if (in_array($role, ['teacher', 'tutor'], true)) {
            $ids = [];
            foreach ($this->em->getRepository(TeacherAssignment::class)->findBy(['teacher' => $user]) as $a) {
                $ids[$a->getSubject()->getId()] = true;
            }
            return array_keys($ids);
        }
        if ($user->getInstitution() !== null) {
            return array_map(
                static fn (Subject $s) => $s->getId(),
                $this->em->getRepository(Subject::class)->findBy(['institution' => $user->getInstitution()])
            );
        }
        return [];
    }
}
