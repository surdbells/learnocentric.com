<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\AcademicSession;
use App\Domain\Entity\Role;
use App\Domain\Entity\Term;
use App\Service\AcademicCalendar;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Settings → Academic calendar.
 *  GET  /school/academic-calendar              sessions (newest first) with their terms
 *  PUT  /school/academic-calendar/terms/{id}   {name?, start_date?, end_date?, is_current?}
 *  POST /school/academic-calendar/sessions     {name} start the next academic year
 */
final class AcademicCalendarAction
{
    use ResolvesInstitution;

    private const EDITORS = [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD, Role::SUPER_ADMIN];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AcademicCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution in scope.', 422);
        }
        $this->calendar->ensureFor($institution);

        $archived = $this->calendar->archivedSessionIds($institution);
        $out = [];
        foreach ($this->em->getRepository(AcademicSession::class)->findBy(['institution' => $institution], ['id' => 'DESC']) as $s) {
            /** @var AcademicSession $s */
            $terms = $this->em->getRepository(Term::class)->findBy(['session' => $s], ['sequence' => 'ASC']);
            $out[] = $s->toArray() + [
                'terms' => array_map(static fn (Term $t) => $t->toArray(), $terms),
                // Retired by the archive policy: kept here, hidden from the term switcher.
                'archived' => in_array((int) $s->getId(), $archived, true),
            ];
        }

        return Json::write($response, ['sessions' => $out, 'suggested_next' => $this->nextName($out[0]['name'] ?? null)]);
    }

    public function updateTerm(Request $request, Response $response, array $args): Response
    {
        if (($deny = Guard::roles($request, $response, self::EDITORS, 'Only administrators can edit the academic calendar.')) !== null) {
            return $deny;
        }
        $term = $this->em->getRepository(Term::class)->find((int) $args['id']);
        if ($term === null || !$this->canActWithin($request, $term->getSession()->getInstitution())) {
            return Json::error($response, 'Term not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $term->setName(mb_substr(trim((string) $body['name']), 0, 50));
        }
        try {
            $start = array_key_exists('start_date', $body) ? $this->date($body['start_date']) : false;
            $end = array_key_exists('end_date', $body) ? $this->date($body['end_date']) : false;
        } catch (Throwable) {
            return Json::error($response, 'Use real dates (YYYY-MM-DD).', 422);
        }
        if ($start !== false) {
            $term->setStartDate($start);
        }
        if ($end !== false) {
            $term->setEndDate($end);
        }
        $s = $term->toArray();
        if ($s['start_date'] && $s['end_date'] && $s['end_date'] < $s['start_date']) {
            return Json::error($response, 'A term must end after it starts.', 422);
        }
        if (!empty($body['is_current'])) {
            $this->makeCurrent($term);
        }
        $this->em->flush();
        $this->audit->log('calendar.term_update', $request->getAttribute('user'), 'Term', (string) $term->getId(), null, $term->toArray());

        return Json::write($response, $term->toArray());
    }

    public function startSession(Request $request, Response $response): Response
    {
        if (($deny = Guard::roles($request, $response, self::EDITORS, 'Only administrators can edit the academic calendar.')) !== null) {
            return $deny;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution in scope.', 422);
        }
        $name = trim((string) (((array) $request->getParsedBody())['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            return Json::error($response, 'Give the session a name, e.g. 2027/2028.', 422);
        }
        if ($this->em->getRepository(AcademicSession::class)->findOneBy(['institution' => $institution, 'name' => $name]) !== null) {
            return Json::error($response, 'That session already exists.', 409);
        }
        $session = $this->calendar->startSession($institution, $name, 1);
        $this->audit->log('calendar.session_start', $request->getAttribute('user'), 'AcademicSession', (string) $session->getId(), null, ['name' => $name]);

        return Json::write($response, $session->toArray(), 201);
    }

    /** One current term (and session) per institution. */
    private function makeCurrent(Term $term): void
    {
        $institution = $term->getSession()->getInstitution();
        foreach ($this->em->getRepository(AcademicSession::class)->findBy(['institution' => $institution]) as $s) {
            /** @var AcademicSession $s */
            $s->setCurrent($s->getId() === $term->getSession()->getId());
            foreach ($this->em->getRepository(Term::class)->findBy(['session' => $s]) as $t) {
                /** @var Term $t */
                $t->setCurrent($t->getId() === $term->getId());
            }
        }
    }

    private function date(mixed $v): ?DateTimeImmutable
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new \InvalidArgumentException('bad date');
        }
        return $d;
    }

    /** "2026/2027" → "2027/2028". */
    private function nextName(?string $latest): string
    {
        if ($latest !== null && preg_match('#^(\d{4})/(\d{4})$#', $latest, $m)) {
            return ((int) $m[1] + 1) . '/' . ((int) $m[2] + 1);
        }
        return AcademicCalendar::sessionName();
    }
}
