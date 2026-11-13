<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\AcademicSession;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Term;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every institution needs a current academic session with its terms, or the
 * term switcher, term-scoped topics/schemes/assessments and report cards have
 * nothing to hang on. Onboarding (and a backfill migration) call ensureFor().
 *
 * Defaults follow the Nigerian three-term year (September–July): a session
 * named "2026/2027" with First, Second and Third Term; the current term is
 * picked from today's month. No dates are invented: the school sets real
 * term dates in Settings → Academic calendar.
 */
class AcademicCalendar
{
    public const TERM_NAMES = ['First Term', 'Second Term', 'Third Term'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Create the current session + terms if the institution has none. Returns true when created. */
    public function ensureFor(Institution $institution, ?DateTimeImmutable $today = null): bool
    {
        $existing = $this->em->getRepository(AcademicSession::class)->findOneBy(['institution' => $institution]);
        if ($existing !== null) {
            return false;
        }
        $this->startSession($institution, self::sessionName($today), self::currentSequence($today));

        return true;
    }

    /**
     * Start a session (e.g. the next academic year), making it and the given
     * term current; any previous session/terms stop being current.
     */
    public function startSession(Institution $institution, string $name, int $currentSequence = 1): AcademicSession
    {
        foreach ($this->em->getRepository(AcademicSession::class)->findBy(['institution' => $institution]) as $old) {
            /** @var AcademicSession $old */
            $old->setCurrent(false);
            foreach ($this->em->getRepository(Term::class)->findBy(['session' => $old]) as $t) {
                /** @var Term $t */
                $t->setCurrent(false);
            }
        }
        $session = new AcademicSession($institution, $name);
        $session->setCurrent(true);
        $this->em->persist($session);
        foreach (self::TERM_NAMES as $i => $termName) {
            $term = new Term($session, $termName, $i + 1);
            $term->setCurrent($i + 1 === $currentSequence);
            $this->em->persist($term);
        }
        $this->em->flush();

        return $session;
    }

    /** "2026/2027": the academic year starts in September. */
    public static function sessionName(?DateTimeImmutable $today = null): string
    {
        $today ??= new DateTimeImmutable();
        $y = (int) $today->format('Y');
        $start = (int) $today->format('n') >= 9 ? $y : $y - 1;

        return $start . '/' . ($start + 1);
    }

    /** Sep–Dec → First, Jan–Apr → Second, May–Aug → Third. */
    public static function currentSequence(?DateTimeImmutable $today = null): int
    {
        $m = (int) ($today ?? new DateTimeImmutable())->format('n');

        return $m >= 9 ? 1 : ($m <= 4 ? 2 : 3);
    }
}
