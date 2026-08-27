<?php

declare(strict_types=1);

namespace App\Application\Actions\Learn;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\TutorQuestion;
use App\Domain\Entity\TutorRating;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ask Tutor, a learner's tutoring surface: a directory of their subject
 * tutors (with ratings), asking a tutor a question, and rating a tutor; plus
 * the tutor-side inbox to answer questions. Direct chat reuses messaging.
 */
final class AskTutorAction
{
    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notify,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /ask-tutor/board, the learner's tutors, their own questions, and recent answered Q&A. */
    public function board(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        // Tutors teaching the learner's classes, with the subjects they teach them.
        $tutors = [];
        if (!empty($classIds)) {
            $assignments = $this->em->createQueryBuilder()->select('ta', 't', 's')
                ->from(TeacherAssignment::class, 'ta')->join('ta.teacher', 't')->join('ta.subject', 's')
                ->where('ta.schoolClass IN (:cids)')->setParameter('cids', $classIds)
                ->getQuery()->getResult();
            foreach ($assignments as $ta) {
                /** @var TeacherAssignment $ta */
                $tid = $ta->getTeacher()->getId();
                if (!isset($tutors[$tid])) {
                    $tutors[$tid] = [
                        'id' => $tid,
                        'name' => trim($ta->getTeacher()->getFirstName() . ' ' . $ta->getTeacher()->getLastName()),
                        'profile_image_url' => \App\Service\Storage\FilePath::toUrl($ta->getTeacher()->getProfileImageUrl()),
                        'subjects' => [],
                    ];
                }
                $tutors[$tid]['subjects'][$ta->getSubject()->getName()] = true;
            }
        }
        $tutorRows = [];
        foreach ($tutors as $t) {
            $agg = $this->em->createQueryBuilder()->select('AVG(r.rating) AS avg', 'COUNT(r.id) AS c')
                ->from(TutorRating::class, 'r')->where('r.tutor = :tid')->setParameter('tid', $t['id'])
                ->getQuery()->getSingleResult();
            $mine = $this->em->getRepository(TutorRating::class)->findOneBy(['tutor' => $t['id'], 'student' => $student]);
            $tutorRows[] = [
                'id' => $t['id'],
                'name' => $t['name'],
                'profile_image_url' => $t['profile_image_url'],
                'subjects' => array_keys($t['subjects']),
                'rating' => $agg['avg'] !== null ? round((float) $agg['avg'], 1) : null,
                'rating_count' => (int) $agg['c'],
                'my_rating' => $mine?->getRating(),
            ];
        }
        usort($tutorRows, static fn ($a, $b) => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0));

        $mineQuestions = $this->em->getRepository(TutorQuestion::class)->findBy(['student' => $student], ['id' => 'DESC'], 20);
        // Recently answered questions across the institution (a lightweight "popular questions" feed).
        $answeredQb = $this->em->createQueryBuilder()->select('q')->from(TutorQuestion::class, 'q')->join('q.student', 'st')
            ->where('q.status = :ans')->setParameter('ans', TutorQuestion::ANSWERED)
            ->orderBy('q.answeredAt', 'DESC')->setMaxResults(6);
        if ($student->getInstitution() !== null) {
            $answeredQb->andWhere('st.institution = :inst')->setParameter('inst', $student->getInstitution());
        }

        return Json::write($response, [
            'tutors' => $tutorRows,
            'my_questions' => array_map(static fn (TutorQuestion $q) => $q->toArray(), $mineQuestions),
            'answered' => array_map(static fn (TutorQuestion $q) => $q->toArray(), $answeredQb->getQuery()->getResult()),
            'subjects' => $this->learnerSubjects($classIds),
        ]);
    }

    /** POST /ask-tutor/questions, learner asks {question, subject_id?, tutor_id?}. */
    public function ask(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $text = trim((string) ($body['question'] ?? ''));
        if ($text === '') {
            return Json::error($response, 'Type your question first.', 422);
        }
        $q = new TutorQuestion($student, $text);
        if (!empty($body['subject_id'])) {
            $q->setSubject($this->em->getRepository(Subject::class)->find((int) $body['subject_id']));
        }
        if (!empty($body['tutor_id'])) {
            $tutor = $this->em->getRepository(User::class)->find((int) $body['tutor_id']);
            $q->setTutor($tutor);
            if ($tutor !== null) {
                $this->notify->notify($tutor, 'message', 'New question from ' . trim($student->getFirstName() . ' ' . $student->getLastName()), mb_strimwidth($text, 0, 120, '…'), '/teacher/ask-tutor', false);
            }
        }
        $this->em->persist($q);
        $this->em->flush();
        $this->audit->log('tutor.ask', $student, 'TutorQuestion', (string) $q->getId(), null, null);

        return Json::write($response, $q->toArray(), 201);
    }

    /** POST /ask-tutor/ratings, learner rates {tutor_id, rating, comment?}. Upsert. */
    public function rate(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $tutor = $this->em->getRepository(User::class)->find((int) ($body['tutor_id'] ?? 0));
        $rating = (int) ($body['rating'] ?? 0);
        if ($tutor === null || $rating < 1 || $rating > 5) {
            return Json::error($response, 'Choose a tutor and a rating from 1 to 5.', 422);
        }
        $existing = $this->em->getRepository(TutorRating::class)->findOneBy(['tutor' => $tutor, 'student' => $student]);
        if ($existing === null) {
            $existing = new TutorRating($student, $tutor, $rating);
            $this->em->persist($existing);
        } else {
            $existing->setRating($rating);
        }
        $existing->setComment($this->str($body['comment'] ?? null));
        $this->em->flush();

        return Json::write($response, ['tutor_id' => $tutor->getId(), 'rating' => $existing->getRating()], 201);
    }

    /** GET /ask-tutor/inbox, tutor's incoming questions (directed to them or unanswered in their subjects). */
    public function inbox(Request $request, Response $response): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $me = $this->currentUser($request);
        $subjectIds = [];
        foreach ($this->em->getRepository(TeacherAssignment::class)->findBy(['teacher' => $me]) as $ta) {
            $subjectIds[$ta->getSubject()->getId()] = true;
        }
        $qb = $this->em->createQueryBuilder()->select('q')->from(TutorQuestion::class, 'q')
            ->orderBy('q.status', 'ASC')->addOrderBy('q.id', 'DESC');
        if ($subjectIds !== []) {
            $qb->where('q.tutor = :me OR q.subject IN (:subs)')
                ->setParameter('me', $me)->setParameter('subs', array_keys($subjectIds));
        } else {
            $qb->where('q.tutor = :me')->setParameter('me', $me);
        }
        $rows = $qb->getQuery()->getResult();

        return Json::write($response, [
            'data' => array_map(static fn (TutorQuestion $q) => $q->toArray(), $rows),
            'meta' => ['open' => count(array_filter($rows, static fn (TutorQuestion $q) => $q->getStatus() === TutorQuestion::OPEN))],
        ]);
    }

    /** POST /ask-tutor/questions/{id}/answer, tutor answers {answer}. */
    public function answerQuestion(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $me = $this->currentUser($request);
        $q = $this->em->getRepository(TutorQuestion::class)->find((int) $args['id']);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        $answer = trim((string) (((array) $request->getParsedBody())['answer'] ?? ''));
        if ($answer === '') {
            return Json::error($response, 'Type your answer first.', 422);
        }
        $q->answer($answer, $me);
        $this->em->flush();
        $this->notify->notify($q->getStudent(), 'message', 'Your tutor answered your question', mb_strimwidth($answer, 0, 120, '…'), '/student/academics/ask-tutor', false);
        $this->audit->log('tutor.answer', $me, 'TutorQuestion', (string) $q->getId(), null, null);

        return Json::write($response, $q->toArray());
    }

    // --- helpers ---

    private function learnerSubjects(array $classIds): array
    {
        if (empty($classIds)) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()->select('DISTINCT s.id, s.name')
            ->from(TeacherAssignment::class, 'ta')->join('ta.subject', 's')
            ->where('ta.schoolClass IN (:cids)')->setParameter('cids', $classIds)
            ->orderBy('s.name', 'ASC')->getQuery()->getArrayResult();
        return array_map(static fn ($r) => ['id' => $r['id'], 'name' => $r['name']], $rows);
    }

    /** @return int[] */
    private function studentClassIds(User $student): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $student]) as $e) {
            $ids[] = $e->getSchoolClass()->getId();
        }
        return array_values(array_unique($ids));
    }

    private function staffGuard(Request $request, Response $response): ?Response
    {
        if (!in_array($this->currentUser($request)->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only tutors can do that.', 403);
        }
        return null;
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
