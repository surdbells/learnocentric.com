<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\AttemptAnswer;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Misconception insights: which questions students miss most, so teachers know
 * what to re-teach. Reads graded attempt answers (spec §14 correction loop).
 */
final class InsightsAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /assessment/insights — most-missed questions with the topic's known misconceptions. */
    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can view insights.', 403);
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $filters = $request->getQueryParams();

        $qb = $this->em->createQueryBuilder()->select('aa')->from(AttemptAnswer::class, 'aa')
            ->join('aa.attempt', 'at')->join('at.assessment', 'a')->join('a.subject', 's')
            ->join('aa.question', 'q')->join('q.topic', 't')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if (!empty($filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $filters['subject_id']);
        }
        if (!empty($filters['topic_id'])) {
            $qb->andWhere('q.topic = :tid')->setParameter('tid', (int) $filters['topic_id']);
        }

        // Aggregate per question: total answered vs missed.
        $agg = [];
        foreach ($qb->getQuery()->getResult() as $answer) {
            /** @var AttemptAnswer $answer */
            $question = $answer->getQuestion();
            $qid = (int) $question->getId();
            if (!isset($agg[$qid])) {
                $topicData = $question->getTopic()->toArray();
                $agg[$qid] = [
                    'question_id' => $qid,
                    'stem' => $question->getStem(),
                    'type' => $question->getType(),
                    'topic_id' => $topicData['id'],
                    'topic' => $topicData['title'],
                    'subject' => $topicData['subject'],
                    'misconceptions' => $topicData['misconceptions'] ?? null,
                    'attempts' => 0,
                    'wrong' => 0,
                ];
            }
            $agg[$qid]['attempts']++;
            if ($answer->isCorrect() === false) {
                $agg[$qid]['wrong']++;
            }
        }

        $rows = array_values(array_filter($agg, static fn (array $r) => $r['wrong'] > 0));
        foreach ($rows as &$row) {
            $row['miss_rate'] = $row['attempts'] > 0 ? round($row['wrong'] / $row['attempts'] * 100, 1) : 0.0;
        }
        unset($row);
        usort($rows, static fn (array $a, array $b) => $b['miss_rate'] <=> $a['miss_rate']);

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }
}
