<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\Question;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /curriculum/map, the curriculum coverage map: topics grouped by subject
 * (optionally scoped to a class + term), each carrying delivery-pack, assessment,
 * question-bank and portfolio readiness so gaps in the curriculum are visible at
 * a glance. A topic counts as "ready" once it has a published pack + assessment.
 */
final class CurriculumMapAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        $params = $request->getQueryParams();
        $classId = isset($params['class_id']) && $params['class_id'] !== '' ? (int) $params['class_id'] : null;
        $termId = isset($params['term_id']) && $params['term_id'] !== '' ? (int) $params['term_id'] : null;

        $qb = $this->em->createQueryBuilder()->select('t', 's')->from(Topic::class, 't')->join('t.subject', 's')
            ->orderBy('s.name', 'ASC')->addOrderBy('t.weekNumber', 'ASC')->addOrderBy('t.title', 'ASC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($classId !== null) {
            $qb->andWhere('t.schoolClass = :cid OR t.schoolClass IS NULL')->setParameter('cid', $classId);
        }
        if ($termId !== null) {
            $qb->andWhere('t.term = :tid')->setParameter('tid', $termId);
        }
        /** @var Topic[] $topics */
        $topics = $qb->getQuery()->getResult();
        $arrays = array_map(static fn (Topic $t) => $t->toArray(), $topics);
        $topicIds = array_map(static fn (array $a) => $a['id'], $arrays);

        $packStatus = $this->packStatusByTopic($topicIds);
        $assessmentStatus = $this->assessmentStatusByTopic($topicIds);
        $questionCount = $this->questionCountByTopic($topicIds);

        $bySubject = [];
        $ready = 0;
        foreach ($arrays as $a) {
            $tid = $a['id'];
            $pack = $packStatus[$tid] ?? 'none';
            $assessment = $assessmentStatus[$tid] ?? 'none';
            $portfolio = !empty($a['portfolio_evidence_expected']) ? 'expected' : 'none';
            $questions = $questionCount[$tid] ?? 0;
            $topicReady = $pack === Lifecycle::PUBLISHED && $assessment === Lifecycle::PUBLISHED;
            if ($topicReady) {
                $ready++;
            }

            $sid = $a['subject_id'];
            $bySubject[$sid] ??= ['subject_id' => $sid, 'subject' => $a['subject'], 'topics' => [], 'ready' => 0, 'total' => 0];
            $bySubject[$sid]['topics'][] = [
                'id' => $tid,
                'week_number' => $a['week_number'],
                'title' => $a['title'],
                'status' => $a['approval_status'],
                'pack' => $pack,
                'assessment' => $assessment,
                'portfolio' => $portfolio,
                'questions' => $questions,
                'ready' => $topicReady,
            ];
            $bySubject[$sid]['total']++;
            $bySubject[$sid]['ready'] += $topicReady ? 1 : 0;
        }
        foreach ($bySubject as &$sub) {
            $sub['coverage_pct'] = $sub['total'] > 0 ? (int) round($sub['ready'] / $sub['total'] * 100) : 0;
        }
        unset($sub);

        $total = count($arrays);
        return Json::write($response, [
            'subjects' => array_values($bySubject),
            'stats' => [
                'subjects' => count($bySubject),
                'topics' => $total,
                'ready' => $ready,
                'coverage_pct' => $total > 0 ? (int) round($ready / $total * 100) : 0,
                'packs_published' => count(array_filter($packStatus, static fn ($s) => $s === Lifecycle::PUBLISHED)),
                'assessments_published' => count(array_filter($assessmentStatus, static fn ($s) => $s === Lifecycle::PUBLISHED)),
                'portfolio_tasks' => count(array_filter($arrays, static fn ($a) => !empty($a['portfolio_evidence_expected']))),
            ],
        ]);
    }

    /**
     * Best delivery-pack status per topic (published beats draft).
     *
     * @param int[] $topicIds
     * @return array<int, string>
     */
    private function packStatusByTopic(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }
        $out = [];
        $rows = $this->em->createQueryBuilder()->select('p', 'pt')->from(TopicDeliveryPack::class, 'p')
            ->join('p.topic', 'pt')->where('pt.id IN (:ids)')->setParameter('ids', $topicIds)->getQuery()->getResult();
        foreach ($rows as $p) {
            /** @var TopicDeliveryPack $p */
            $tid = $p->toArray()['topic_id'];
            if (($out[$tid] ?? null) !== Lifecycle::PUBLISHED) {
                $out[$tid] = $p->getStatus();
            }
        }
        return $out;
    }

    /**
     * Best assessment approval status per topic (published beats anything else).
     *
     * @param int[] $topicIds
     * @return array<int, string>
     */
    private function assessmentStatusByTopic(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }
        $out = [];
        $rows = $this->em->createQueryBuilder()->select('a', 'at')->from(Assessment::class, 'a')
            ->join('a.topic', 'at')->where('at.id IN (:ids)')->setParameter('ids', $topicIds)->getQuery()->getResult();
        foreach ($rows as $a) {
            /** @var Assessment $a */
            $tid = $a->getTopic()?->getId();
            if ($tid === null) {
                continue;
            }
            if (($out[$tid] ?? null) !== Lifecycle::PUBLISHED) {
                $out[$tid] = $a->getApprovalStatus();
            }
        }
        return $out;
    }

    /**
     * Question-bank count per topic.
     *
     * @param int[] $topicIds
     * @return array<int, int>
     */
    private function questionCountByTopic(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(q.topic) AS tid', 'COUNT(q.id) AS c')
            ->from(Question::class, 'q')->where('q.topic IN (:ids)')->setParameter('ids', $topicIds)
            ->groupBy('q.topic')->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['tid']] = (int) $r['c'];
        }
        return $out;
    }
}
