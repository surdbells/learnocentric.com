<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\Worksheet;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/school/calendar, academic events for the institution.
 *
 * Aggregates the dated things the app already tracks, scheduled live classes and
 * worksheet due dates, into a single, sorted events feed with per-type counts.
 * Institution-scoped like the other school actions.
 */
final class CalendarAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $inst = $this->resolveInstitution($request, $this->em);

        // Live classes (scheduled).
        $lcQb = $this->em->createQueryBuilder()
            ->select('lc.title AS title, s.name AS subject, lc.scheduledAt AS at, lc.status AS status')
            ->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->orderBy('lc.scheduledAt', 'ASC');
        if ($inst !== null) {
            $lcQb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }

        // Worksheet due dates.
        $wsQb = $this->em->createQueryBuilder()
            ->select('w.title AS title, s.name AS subject, w.dueDate AS at')
            ->from(Worksheet::class, 'w')->join('w.topic', 't')->join('t.subject', 's')
            ->where('w.dueDate IS NOT NULL')
            ->orderBy('w.dueDate', 'ASC');
        if ($inst !== null) {
            $wsQb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }

        $events = [];
        foreach ($lcQb->getQuery()->getArrayResult() as $r) {
            /** @var \DateTimeInterface $at */
            $at = $r['at'];
            $events[] = [
                'date' => $at->format('Y-m-d'),
                'datetime' => $at->format(DATE_ATOM),
                'title' => $r['title'],
                'subject' => $r['subject'],
                'type' => 'live_class',
                'status' => $r['status'],
            ];
        }
        foreach ($wsQb->getQuery()->getArrayResult() as $r) {
            /** @var \DateTimeInterface $at */
            $at = $r['at'];
            $events[] = [
                'date' => $at->format('Y-m-d'),
                'datetime' => $at->format('Y-m-d'),
                'title' => $r['title'],
                'subject' => $r['subject'],
                'type' => 'worksheet_due',
                'status' => null,
            ];
        }

        usort($events, static fn ($a, $b) => strcmp($a['datetime'], $b['datetime']));

        $month = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        $stats = [
            'total' => count($events),
            'live_classes' => count(array_filter($events, static fn ($e) => $e['type'] === 'live_class')),
            'worksheet_due' => count(array_filter($events, static fn ($e) => $e['type'] === 'worksheet_due')),
            'this_month' => count(array_filter($events, static fn ($e) => str_starts_with((string) $e['date'], $month))),
        ];

        return Json::write($response, ['events' => $events, 'stats' => $stats]);
    }
}
