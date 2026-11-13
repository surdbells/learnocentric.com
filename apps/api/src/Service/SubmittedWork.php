<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\AttemptAnswer;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\Question;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Topic;
use App\Domain\Entity\Worksheet;
use App\Domain\Entity\WorksheetQuestion;
use App\Domain\Entity\WorksheetResponse;
use App\Domain\Entity\WorksheetSubmission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guards learners' handed-in work against deletion.
 *
 * Deleting a worksheet, an assessment, a question, a topic or a subject removes
 * everything beneath it in the database, including the work learners submitted
 * and the marks they were given. Once work has been handed in, the item is no
 * longer deleted: it is archived instead, which hides it from learners and keeps
 * the record.
 *
 * "Handed in" means submitted or marked. A learner's unfinished draft, or an
 * attempt still in progress, does not hold a deletion back.
 */
final class SubmittedWork
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Why the item cannot be deleted, or null when it holds no handed-in work.
     */
    public function refusal(object $item): ?string
    {
        return match (true) {
            $item instanceof Worksheet => $this->say(
                'worksheet',
                $this->learners($this->worksheetLearners()->andWhere('s.worksheet = :x')->setParameter('x', $item)),
                'submitted work for this worksheet',
                'their work and marks',
                true,
            ),
            $item instanceof Assessment => $this->say(
                'assessment',
                $this->learners($this->attemptLearners()->andWhere('a.assessment = :x')->setParameter('x', $item)),
                'taken this assessment',
                'their answers and results',
                true,
            ),
            $item instanceof Question => $this->say(
                'question',
                $this->learners($this->answerLearners()->andWhere('aa.question = :x')->setParameter('x', $item)),
                'answered this question in an assessment they submitted',
                'their answers from those results',
                true,
            ),
            $item instanceof WorksheetQuestion => $this->say(
                'question',
                $this->learners($this->em->createQueryBuilder()->select('COUNT(DISTINCT IDENTITY(s.student))')
                    ->from(WorksheetResponse::class, 'r')->join('r.submission', 's')
                    ->where('r.question = :x')->andWhere('s.status <> :draft')
                    ->setParameter('x', $item)->setParameter('draft', WorksheetSubmission::DRAFT)),
                'answered this question in work they handed in',
                'their answers',
                false,
            ),
            $item instanceof Topic => $this->container('topic', [
                'worksheet submission' => $this->count($this->em->createQueryBuilder()->select('COUNT(s.id)')
                    ->from(WorksheetSubmission::class, 's')->join('s.worksheet', 'w')
                    ->where('w.topic = :x')->andWhere('s.status <> :draft')
                    ->setParameter('x', $item)->setParameter('draft', WorksheetSubmission::DRAFT)),
                'assessment answer' => $this->count($this->em->createQueryBuilder()->select('COUNT(aa.id)')
                    ->from(AttemptAnswer::class, 'aa')->join('aa.attempt', 'a')->join('aa.question', 'q')
                    ->where('q.topic = :x')->andWhere('a.status <> :open')
                    ->setParameter('x', $item)->setParameter('open', AssessmentAttempt::IN_PROGRESS)),
                'portfolio entry' => $this->count($this->em->createQueryBuilder()->select('COUNT(p.id)')
                    ->from(PortfolioEntry::class, 'p')->where('p.topic = :x')->setParameter('x', $item)),
            ], true),
            $item instanceof Subject => $this->container('subject', [
                'worksheet submission' => $this->count($this->em->createQueryBuilder()->select('COUNT(s.id)')
                    ->from(WorksheetSubmission::class, 's')->join('s.worksheet', 'w')->join('w.topic', 't')
                    ->where('t.subject = :x')->andWhere('s.status <> :draft')
                    ->setParameter('x', $item)->setParameter('draft', WorksheetSubmission::DRAFT)),
                'assessment attempt' => $this->count($this->em->createQueryBuilder()->select('COUNT(a.id)')
                    ->from(AssessmentAttempt::class, 'a')->join('a.assessment', 'x')
                    ->where('x.subject = :x')->andWhere('a.status <> :open')
                    ->setParameter('x', $item)->setParameter('open', AssessmentAttempt::IN_PROGRESS)),
                'portfolio entry' => $this->count($this->em->createQueryBuilder()->select('COUNT(p.id)')
                    ->from(PortfolioEntry::class, 'p')->join('p.topic', 't')
                    ->where('t.subject = :x')->setParameter('x', $item)),
            ], false),
            default => null,
        };
    }

    private function worksheetLearners(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('COUNT(DISTINCT IDENTITY(s.student))')
            ->from(WorksheetSubmission::class, 's')
            ->where('s.status <> :draft')->setParameter('draft', WorksheetSubmission::DRAFT);
    }

    private function attemptLearners(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('COUNT(DISTINCT IDENTITY(a.student))')
            ->from(AssessmentAttempt::class, 'a')
            ->where('a.status <> :open')->setParameter('open', AssessmentAttempt::IN_PROGRESS);
    }

    private function answerLearners(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('COUNT(DISTINCT IDENTITY(a.student))')
            ->from(AttemptAnswer::class, 'aa')->join('aa.attempt', 'a')
            ->where('a.status <> :open')->setParameter('open', AssessmentAttempt::IN_PROGRESS);
    }

    private function learners(\Doctrine\ORM\QueryBuilder $qb): int
    {
        return $this->count($qb);
    }

    private function count(\Doctrine\ORM\QueryBuilder $qb): int
    {
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** One item that learners have worked on directly. */
    private function say(string $noun, int $learners, string $did, string $loses, bool $canArchive): ?string
    {
        if ($learners === 0) {
            return null;
        }

        return sprintf(
            '%d learner%s %s %s. Deleting it would delete %s, so it cannot be deleted.%s',
            $learners,
            $learners === 1 ? '' : 's',
            $learners === 1 ? 'has' : 'have',
            $did,
            $loses,
            $canArchive ? sprintf(' Archive the %s instead: learners will no longer see it and the record is kept.', $noun) : '',
        );
    }

    /**
     * An item that holds other items (a topic, a subject).
     *
     * @param array<string, int> $counts what kind of work => how many
     */
    private function container(string $noun, array $counts, bool $canArchive): ?string
    {
        $parts = [];
        foreach ($counts as $what => $n) {
            if ($n > 0) {
                $plural = str_ends_with($what, 'y') ? substr($what, 0, -1) . 'ies' : $what . 's';
                $parts[] = $n . ' ' . ($n === 1 ? $what : $plural);
            }
        }
        if ($parts === []) {
            return null;
        }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;

        return sprintf(
            'This %s holds work learners have handed in (%s). Deleting it would delete that work and its marks, so it cannot be deleted.%s',
            $noun,
            $list,
            $canArchive ? sprintf(' Archive the %s instead: learners will no longer see it and the record is kept.', $noun) : '',
        );
    }
}
