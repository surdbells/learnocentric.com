<?php

declare(strict_types=1);

namespace App\Application\Actions\Search;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /search?q= — global, permission-aware search over the modules the current
 * user can reach. Each persona searches a different slice; results carry a
 * role-correct deep link so the header search can navigate anywhere in the app.
 */
final class SearchAction
{
    use ResolvesInstitution;

    private const LIMIT_PER_TYPE = 5;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return Json::write($response, ['data' => [], 'meta' => ['query' => $q, 'total' => 0]]);
        }
        $role = $user->getRole()->getCode();
        $base = $this->roleBase($role);
        $needle = mb_strtolower($q);
        $like = '%' . $needle . '%';
        $inst = $this->resolveInstitution($request, $this->em);

        $groups = [];
        if ($role === 'super_admin') {
            $groups[] = $this->institutions($like);
            $groups[] = $this->people($like, null, $base);
            $groups[] = $this->subjects($like, null, $base);
        } elseif (in_array($role, ['school_admin', 'tutor_admin', 'academic_lead', 'teacher'], true)) {
            $groups[] = $this->people($like, $inst, $base);
            $groups[] = $this->subjects($like, $inst, $base);
            $groups[] = $this->topics($like, $inst, false, $base . '/academics/topics');
            $groups[] = $this->assessments($like, $inst, false, $base . '/academics/assessments');
            $groups[] = $this->worksheets($like, $inst, false, $base . '/academics/worksheets');
        } elseif ($role === 'student') {
            $classIds = $this->studentClassIds($user);
            $groups[] = $this->topics($like, $inst, true, '/student/academics/learn', $classIds);
            $groups[] = $this->assessments($like, $inst, true, '/student/academics/assessments');
            $groups[] = $this->worksheets($like, $inst, true, '/student/academics/worksheets');
            $groups[] = $this->resources($needle, $user, '/student/academics/resources');
        }

        $results = array_merge(...array_filter($groups));
        return Json::write($response, ['data' => $results, 'meta' => ['query' => $q, 'total' => count($results)]]);
    }

    private function roleBase(string $role): string
    {
        return match ($role) {
            'super_admin' => '/super-admin/management',
            'tutor_admin' => '/academy',
            'teacher' => '/teacher',
            'student' => '/student',
            'parent' => '/parent',
            default => '/admin',
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function institutions(string $like): array
    {
        $rows = $this->em->createQueryBuilder()->select('i')->from(Institution::class, 'i')
            ->where('LOWER(i.name) LIKE :q')->setParameter('q', $like)->setMaxResults(self::LIMIT_PER_TYPE)->getQuery()->getResult();
        return array_map(static fn (Institution $i) => [
            'type' => 'Institution', 'icon' => 'apartment', 'title' => $i->getName(),
            'subtitle' => ucfirst($i->getType()), 'link' => '/super-admin/management/institutions',
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function people(string $like, ?Institution $inst, string $base): array
    {
        $qb = $this->em->createQueryBuilder()->select('u', 'r')->from(User::class, 'u')->join('u.role', 'r')
            ->where('LOWER(CONCAT(u.firstName, \' \', u.lastName)) LIKE :q OR LOWER(u.email) LIKE :q')->setParameter('q', $like)
            ->andWhere('r.code IN (:roles)')->setParameter('roles', ['student', 'teacher'])
            ->setMaxResults(self::LIMIT_PER_TYPE);
        if ($inst !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $inst);
        }
        return array_map(static function (User $u) use ($base) {
            $isStudent = $u->getRole()->getCode() === 'student';
            return [
                'type' => $isStudent ? 'Learner' : 'Teacher',
                'icon' => $isStudent ? 'group' : 'supervisor_account',
                'title' => $u->getFirstName() . ' ' . $u->getLastName(),
                'subtitle' => $u->getEmail(),
                'link' => $isStudent ? $base . '/students' : $base . '/teachers',
            ];
        }, $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function subjects(string $like, ?Institution $inst, string $base): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(Subject::class, 's')
            ->where('LOWER(s.name) LIKE :q')->setParameter('q', $like)->setMaxResults(self::LIMIT_PER_TYPE);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return array_map(static fn (Subject $s) => [
            'type' => 'Subject', 'icon' => 'subject', 'title' => $s->getName(),
            'subtitle' => 'Subject', 'link' => $base . '/academics/subjects',
        ], $qb->getQuery()->getResult());
    }

    /**
     * @param int[] $classIds
     * @return array<int, array<string, mixed>>
     */
    private function topics(string $like, ?Institution $inst, bool $publishedOnly, string $link, array $classIds = []): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('LOWER(t.title) LIKE :q')->setParameter('q', $like)->setMaxResults(self::LIMIT_PER_TYPE);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        if ($publishedOnly) {
            $qb->andWhere('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
            if ($classIds !== []) {
                $qb->andWhere('t.schoolClass IS NULL OR t.schoolClass IN (:cids)')->setParameter('cids', $classIds);
            }
        }
        return array_map(static fn (Topic $t) => [
            'type' => 'Lesson', 'icon' => 'menu_book', 'title' => $t->getTitle(),
            'subtitle' => $t->getSubject()->getName(), 'link' => $link,
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function assessments(string $like, ?Institution $inst, bool $publishedOnly, string $link): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('LOWER(a.title) LIKE :q')->setParameter('q', $like)->setMaxResults(self::LIMIT_PER_TYPE);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        if ($publishedOnly) {
            $qb->andWhere('a.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        }
        return array_map(static fn (Assessment $a) => [
            'type' => 'Assessment', 'icon' => 'quiz', 'title' => $a->getTitle(),
            'subtitle' => $a->getSubject()->getName() . ' · ' . ucfirst($a->getType()), 'link' => $link,
        ], $qb->getQuery()->getResult());
    }

    /** @return array<int, array<string, mixed>> */
    private function worksheets(string $like, ?Institution $inst, bool $publishedOnly, string $link): array
    {
        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')->join('w.topic', 't')->join('t.subject', 's')
            ->where('LOWER(w.title) LIKE :q')->setParameter('q', $like)->setMaxResults(self::LIMIT_PER_TYPE);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        if ($publishedOnly) {
            $qb->andWhere('w.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        }
        return array_map(static fn (Worksheet $w) => [
            'type' => 'Worksheet', 'icon' => 'assignment_turned_in', 'title' => $w->getTitle(),
            'subtitle' => $w->getTopic()->getSubject()->getName(), 'link' => $link,
        ], $qb->getQuery()->getResult());
    }

    /**
     * @param string $needle lowercase search term (without % wrappers)
     * @return array<int, array<string, mixed>>
     */
    private function resources(string $needle, User $student, string $link): array
    {
        $packageId = $student->getInstitution()?->getAssignedPackageId();
        if ($packageId === null) {
            return [];
        }
        $package = $this->em->getRepository(\App\Domain\Entity\ContentPackage::class)->find($packageId);
        if ($package === null) {
            return [];
        }
        $out = [];
        foreach ($package->getResources() as $r) {
            /** @var ContentResource $r */
            $arr = $r->toArray();
            if ($arr['licence_status'] !== ContentResource::APPROVED || ($arr['visibility'] ?? 'published') !== 'published') {
                continue;
            }
            if (!str_contains(mb_strtolower((string) $arr['title']), $needle)) {
                continue;
            }
            $out[] = ['type' => 'Resource', 'icon' => 'library_books', 'title' => $arr['title'], 'subtitle' => ucfirst((string) $arr['contentType']), 'link' => $link];
            if (count($out) >= self::LIMIT_PER_TYPE) {
                break;
            }
        }
        return $out;
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
}
