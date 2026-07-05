<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Entity\AcademicSession;
use App\Domain\Entity\AuditLog;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Permission;
use App\Domain\Entity\Question;
use App\Domain\Entity\Role;
use App\Domain\Entity\RolePermission;
use App\Domain\Entity\SchemeOfWork;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\Term;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Service\PasswordService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Idempotent seeder: roles, permissions, RBAC grants, and baseline accounts. */
class SeedCommand extends Command
{
    protected static $defaultName = 'app:seed';
    protected static $defaultDescription = 'Seed baseline roles, permissions and demo accounts.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordService $passwords,
    ) {
        parent::__construct('app:seed');
    }

    protected function configure(): void
    {
        $this->setDescription((string) self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roles = $this->seedRoles();
        $this->seedPermissions();
        $this->seedGrants($roles);
        $users = $this->seedAccounts($roles, $output);
        $this->seedAcademicSpine($users, $output);
        $this->seedEnrollments($users, $output);
        $this->seedContentVersions($output);
        $this->seedQuestions($output);
        $this->seedAuditLogs($users, $output);

        $this->em->flush();
        $output->writeln('<info>Seed complete.</info>');

        return Command::SUCCESS;
    }

    /**
     * Seeds the JSS 1 Mathematics pilot (spec §21) so every spine table has data:
     * session, term, class, subject, teacher assignment, scheme of work, topics, delivery packs.
     *
     * @param array<string,User> $users
     */
    private function seedAcademicSpine(array $users, OutputInterface $output): void
    {
        $school = ($users['school@gmail.com'] ?? null)?->getInstitution();
        if ($school === null) {
            return;
        }
        if ($this->em->getRepository(AcademicSession::class)->findOneBy(['institution' => $school]) !== null) {
            return; // already seeded
        }

        $teacher = $users['teacher@gmail.com'];
        $teacher2 = $users['teacher2@gmail.com'];

        // Session + terms
        $session = new AcademicSession($school, '2025/2026');
        $session->setStartDate(new DateTimeImmutable('2025-09-15'));
        $session->setEndDate(new DateTimeImmutable('2026-07-31'));
        $session->setCurrent(true);
        $this->em->persist($session);

        $firstTerm = new Term($session, 'First Term', 1);
        $firstTerm->setStartDate(new DateTimeImmutable('2025-09-15'));
        $firstTerm->setEndDate(new DateTimeImmutable('2025-12-12'));
        $firstTerm->setCurrent(true);
        $this->em->persist($firstTerm);
        $this->em->persist(new Term($session, 'Second Term', 2));
        $this->em->persist(new Term($session, 'Third Term', 3));

        // Class + subjects
        $class = new SchoolClass($school, 'JSS 1', 'A');
        $class->setClassTeacher($teacher);
        $this->em->persist($class);

        $maths = new Subject($school, 'Mathematics');
        $maths->setCode('MTH');
        $maths->setSchoolClass($class);
        $this->em->persist($maths);

        $english = new Subject($school, 'English Language');
        $english->setCode('ENG');
        $english->setSchoolClass($class);
        $this->em->persist($english);

        $science = new Subject($school, 'Basic Science');
        $science->setCode('BSC');
        $science->setSchoolClass($class);
        $this->em->persist($science);

        // Teacher assignments
        $this->em->persist(new TeacherAssignment($teacher, $class, $maths, $firstTerm));
        $this->em->persist(new TeacherAssignment($teacher2, $class, $english, $firstTerm));
        $this->em->flush();

        // Topics (JSS 1 Mathematics, First Term) + scheme + delivery packs
        $topics = [
            [1, 'Whole Numbers', 'Read, write, count and order whole numbers up to millions.', 'Counting money, reading meter/odometer values, phone/account numbers.', 'Recognising place value in everyday quantities.'],
            [2, 'LCM (Lowest Common Multiple)', 'Find the LCM of two or three numbers using multiples and prime factors.', 'Scheduling events that repeat at different intervals (buses, shifts).', 'Planning recurring tasks efficiently.'],
            [3, 'HCF (Highest Common Factor)', 'Find the HCF of numbers using factors and prime factorisation.', 'Sharing items into equal largest groups without remainder.', 'Fair distribution and simplification.'],
            [4, 'Fractions', 'Interpret, compare and simplify fractions; convert to decimals.', 'Recipes, sharing food, discounts and measurements.', 'Proportional reasoning in daily life.'],
            [5, 'Basic Operations', 'Add, subtract, multiply and divide whole numbers accurately.', 'Budgeting, market transactions, computing change.', 'Everyday numeracy and money handling.'],
        ];

        foreach ($topics as [$week, $title, $objective, $realLife, $competency]) {
            $topic = new Topic($maths, $title);
            $topic->setSchoolClass($class);
            $topic->setTerm($firstTerm);
            $topic->setWeekNumber($week);
            $topic->setStrand('Number and Numeration');
            $topic->setObjective($objective);
            $topic->setCoreTheory('Core theory and worked examples for ' . $title . '.');
            $topic->setRealLifeRelevance($realLife);
            $topic->setCompetencyBuilt($competency);
            $topic->setApprovalStatus($week <= 3 ? Lifecycle::PUBLISHED : Lifecycle::APPROVED);
            $this->em->persist($topic);

            $scheme = new SchemeOfWork($class, $maths, $week);
            $scheme->setTerm($firstTerm);
            $scheme->setTopic($topic);
            $scheme->setObjective($objective);
            $scheme->setAssignedTeacher($teacher);
            $scheme->setStatus($week <= 3 ? Lifecycle::PUBLISHED : Lifecycle::APPROVED);
            $this->em->persist($scheme);

            if ($week <= 2) {
                $pack = new TopicDeliveryPack($topic);
                $pack->setTeacherGuide('Teacher guide: introduce ' . $title . ' with a diagnostic starter, then guided examples.');
                $pack->setLearnerNote('Learner note explaining ' . $title . ' in clear Nigerian-context English.');
                $pack->setParentWording('Your child is learning ' . $title . '. Ask them to show one everyday example.');
                $pack->setStatus(Lifecycle::PUBLISHED);
                $this->em->persist($pack);
            }
        }

        $this->em->flush();
        $output->writeln('  + academic spine: session, 3 terms, 1 class, 3 subjects, 2 assignments, 5 topics, 5 scheme weeks, 2 delivery packs');
    }

    /** @return array<string,Role> */
    private function seedRoles(): array
    {
        $definitions = [
            [Role::SUPER_ADMIN, 'Super Admin', 'platform'],
            [Role::SCHOOL_ADMIN, 'School Admin', 'school'],
            [Role::TUTOR_ADMIN, 'Tutor Admin', 'school'],
            [Role::ACADEMIC_LEAD, 'Academic Lead', 'school'],
            [Role::TEACHER, 'Teacher', 'school'],
            [Role::STUDENT, 'Student', 'school'],
            [Role::PARENT, 'Parent / Guardian', 'school'],
        ];

        $repo = $this->em->getRepository(Role::class);
        $roles = [];
        foreach ($definitions as [$code, $name, $scope]) {
            $role = $repo->findOneBy(['code' => $code]) ?? new Role($code, $name, $scope);
            $this->em->persist($role);
            $roles[$code] = $role;
        }
        $this->em->flush();

        return $roles;
    }

    private function seedPermissions(): void
    {
        $subjects = [
            'institution' => 'Institutions / schools',
            'user' => 'User accounts',
            'role' => 'Roles and permissions',
            'academic_setup' => 'Session, term, class, subject setup',
            'curriculum_pack' => 'Curriculum packs',
            'delivery_pack' => 'Topic delivery packs',
            'assessment' => 'Assessments and questions',
            'worksheet' => 'Worksheets',
            'portfolio' => 'Portfolio tasks and evidence',
            'gradebook' => 'Gradebook and scores',
            'live_class' => 'Live classes',
            'resource' => 'Learning resources',
            'report' => 'Reports',
            'intervention' => 'Interventions',
            'safeguarding_case' => 'Safeguarding cases',
            'support_ticket' => 'Support tickets',
            'subscription' => 'Subscriptions and billing',
            'audit_log' => 'Audit logs',
        ];

        $repo = $this->em->getRepository(Permission::class);
        foreach ($subjects as $code => $desc) {
            if ($repo->findOneBy(['code' => $code]) === null) {
                $this->em->persist(new Permission($code, $desc));
            }
        }
        $this->em->flush();
    }

    /** @param array<string,Role> $roles */
    private function seedGrants(array $roles): void
    {
        $all = ['view' => true, 'create' => true, 'edit' => true, 'approve' => true, 'export' => true, 'delete' => true];
        $rw = ['view' => true, 'create' => true, 'edit' => true];
        $ro = ['view' => true];

        $matrix = [
            Role::SCHOOL_ADMIN => [
                'academic_setup' => $all, 'user' => $rw, 'curriculum_pack' => $ro, 'delivery_pack' => ['view' => true, 'approve' => true],
                'assessment' => ['view' => true, 'approve' => true], 'gradebook' => ['view' => true, 'approve' => true, 'export' => true],
                'report' => ['view' => true, 'export' => true], 'intervention' => $rw, 'resource' => ['view' => true, 'approve' => true],
                'live_class' => $ro, 'safeguarding_case' => $rw, 'subscription' => $ro,
            ],
            Role::TEACHER => [
                'delivery_pack' => ['view' => true, 'create' => true, 'edit' => true], 'assessment' => $rw, 'worksheet' => $rw,
                'portfolio' => ['view' => true, 'edit' => true], 'gradebook' => $rw, 'live_class' => $rw,
                'resource' => ['view' => true, 'create' => true], 'report' => $ro, 'intervention' => $ro,
            ],
            Role::STUDENT => [
                'delivery_pack' => $ro, 'assessment' => $ro, 'worksheet' => ['view' => true, 'create' => true],
                'portfolio' => ['view' => true, 'create' => true], 'resource' => $ro, 'report' => $ro,
            ],
        ];
        $matrix[Role::TUTOR_ADMIN] = $matrix[Role::SCHOOL_ADMIN];
        $matrix[Role::ACADEMIC_LEAD] = $matrix[Role::SCHOOL_ADMIN];

        $repo = $this->em->getRepository(RolePermission::class);
        foreach ($matrix as $roleCode => $grants) {
            $role = $roles[$roleCode];
            foreach ($grants as $permCode => $actions) {
                $existing = $repo->findOneBy(['role' => $role, 'permissionCode' => $permCode]);
                if ($existing === null) {
                    $this->em->persist(new RolePermission($role, $permCode, $actions, $role->getScope()));
                }
            }
        }
        $this->em->flush();
    }

    /**
     * @param array<string,Role> $roles
     * @return array<string,User> keyed by email
     */
    private function seedAccounts(array $roles, OutputInterface $output): array
    {
        $userRepo = $this->em->getRepository(User::class);

        // Both institution types: a regular school and a tutoring academy.
        $school = $this->ensureInstitution('GOF College', 'school', 'Lagos, Nigeria');
        $academy = $this->ensureInstitution('Bright Minds Academy', 'academy', 'Abuja, Nigeria');

        // [email, first, last, role, institution, phone]
        $accounts = [
            ['surdbells@gmail.com', 'LEARN O', 'CENTRIC', Role::SUPER_ADMIN, null, '+2348000000000'],

            // Regular school (GOF College)
            ['school@gmail.com', 'GOF', 'College', Role::SCHOOL_ADMIN, $school, '+2348011111111'],
            ['lead@gmail.com', 'Amina', 'Bello', Role::ACADEMIC_LEAD, $school, '+2348011111112'],
            ['teacher@gmail.com', 'Ibrahim', 'Yekini', Role::TEACHER, $school, '+2348022222221'],
            ['teacher2@gmail.com', 'Ngozi', 'Okafor', Role::TEACHER, $school, '+2348022222222'],
            ['student@gmail.com', 'Bello', 'Sodiq', Role::STUDENT, $school, null],
            ['student2@gmail.com', 'Chiamaka', 'Eze', Role::STUDENT, $school, null],
            ['student3@gmail.com', 'Tunde', 'Adeyemi', Role::STUDENT, $school, null],
            ['parent@gmail.com', 'Fatima', 'Sodiq', Role::PARENT, $school, '+2348033333331'],

            // Tutoring academy (Bright Minds Academy)
            ['academy@gmail.com', 'Bright', 'Minds', Role::TUTOR_ADMIN, $academy, '+2348044444441'],
            ['tutor@gmail.com', 'Emeka', 'Nwosu', Role::TEACHER, $academy, '+2348044444442'],
            ['learner@gmail.com', 'Zainab', 'Musa', Role::STUDENT, $academy, null],
        ];

        $users = [];
        foreach ($accounts as [$email, $first, $last, $roleCode, $institution, $phone]) {
            $user = $userRepo->findOneBy(['email' => $email]);
            if ($user === null) {
                $user = new User($email, $first, $last, $roles[$roleCode]);
                $user->setPasswordHash($this->passwords->hash('Password@1'));
                $user->setInstitution($institution);
                $user->setPhone($phone);
                $this->em->persist($user);
                $output->writeln("  + user {$email} ({$roleCode})");
            }
            $users[$email] = $user;
        }
        $this->em->flush();

        return $users;
    }

    private function ensureInstitution(string $name, string $type, string $address): Institution
    {
        $repo = $this->em->getRepository(Institution::class);
        $institution = $repo->findOneBy(['name' => $name]);
        if ($institution === null) {
            $institution = new Institution($name);
            $institution->setType($type);
            $institution->setAddress($address);
            $this->em->persist($institution);
            $this->em->flush();
        }

        return $institution;
    }

    /** Enroll the demo students into JSS 1 A. @param array<string,User> $users */
    private function seedEnrollments(array $users, OutputInterface $output): void
    {
        if ($this->em->getRepository(Enrollment::class)->count([]) > 0) {
            return;
        }
        $class = $this->em->getRepository(SchoolClass::class)->findOneBy([]);
        if ($class === null) {
            return;
        }
        $session = $this->em->getRepository(AcademicSession::class)->findOneBy([]);
        $term = $this->em->getRepository(Term::class)->findOneBy(['isCurrent' => true]);

        $count = 0;
        foreach (['student@gmail.com', 'student2@gmail.com', 'student3@gmail.com'] as $email) {
            $student = $users[$email] ?? null;
            if ($student === null) {
                continue;
            }
            $enrollment = new Enrollment($student, $class);
            $enrollment->setSession($session);
            $enrollment->setTerm($term);
            $enrollment->setEnrollmentDate(new DateTimeImmutable('2025-09-16'));
            $this->em->persist($enrollment);
            $count++;
        }
        $this->em->flush();
        $output->writeln("  + {$count} enrollments");
    }

    /** Seed the question bank (JSS 1 Maths) — including one draft that fails the answer gate. */
    private function seedQuestions(OutputInterface $output): void
    {
        if ($this->em->getRepository(Question::class)->count([]) > 0) {
            return;
        }
        $repo = $this->em->getRepository(Topic::class);
        $whole = $repo->findOneBy(['title' => 'Whole Numbers']);
        $lcm = $repo->findOneBy(['title' => 'LCM (Lowest Common Multiple)']);
        $hcf = $repo->findOneBy(['title' => 'HCF (Highest Common Factor)']);
        $frac = $repo->findOneBy(['title' => 'Fractions']);
        if ($whole === null) {
            return;
        }

        $mcq = static fn (array $opts) => array_map(static fn ($k, $t) => ['key' => $k, 'text' => $t], array_keys($opts), $opts);

        // [topic, type, track, difficulty, stem, options, correct, explanation, marks, status, validated]
        $defs = [
            [$whole, 'mcq', 'academic', 'easy', 'What is the place value of 7 in 4,750?',
                $mcq(['a' => '7', 'b' => '70', 'c' => '700', 'd' => '7,000']), 'c',
                '7 sits in the hundreds column, so its place value is 700.', 1, Lifecycle::PUBLISHED, true],
            [$whole, 'numeric', 'academic', 'easy', 'Write “two thousand and five” in figures.',
                null, ['value' => 2005, 'tolerance' => 0],
                'Two thousand = 2000, and five units = 5, giving 2005.', 1, Lifecycle::PUBLISHED, true],
            [$lcm, 'mcq', 'academic', 'medium', 'What is the LCM of 4 and 6?',
                $mcq(['a' => '12', 'b' => '24', 'c' => '2', 'd' => '10']), 'a',
                'Multiples of 4 and 6 first meet at 12.', 2, Lifecycle::PUBLISHED, true],
            [$hcf, 'mcq', 'academic', 'medium', 'What is the HCF of 12 and 18?',
                $mcq(['a' => '3', 'b' => '6', 'c' => '9', 'd' => '36']), 'b',
                'The largest factor common to 12 and 18 is 6.', 2, Lifecycle::PUBLISHED, true],
            [$hcf, 'true_false', 'academic', 'hard', 'The HCF of any two different prime numbers is always 1.',
                null, 'true',
                'Distinct primes share no factor other than 1.', 1, Lifecycle::APPROVED, true],
            [$frac, 'short', 'academic', 'easy', 'Simplify 4/8 to its lowest term.',
                null, null, // deliberately no answer yet — must fail the validation gate
                null, 1, Lifecycle::DRAFT, false],
        ];

        $count = 0;
        foreach ($defs as [$topic, $type, $track, $difficulty, $stem, $options, $correct, $explanation, $marks, $status, $validated]) {
            $q = new Question($topic, $stem);
            $q->setType($type);
            $q->setTrack($track);
            $q->setDifficulty($difficulty);
            $q->setOptions($options);
            $q->setCorrectAnswer($correct);
            $q->setExplanation($explanation);
            $q->setMarks($marks);
            $q->setAnswerValidated($validated);
            $q->setApprovalStatus($status);
            $this->em->persist($q);
            $this->em->flush();

            $version = new ContentVersion('Question', (int) $q->getId(), 1, $status, 'seed');
            $version->setFromStatus(null);
            $version->setSnapshot($q->toArray());
            $version->setNote('Baseline version created during seed.');
            $this->em->persist($version);
            $count++;
        }
        $this->em->flush();
        $output->writeln("  + {$count} questions (question bank)");
    }

    /** Seed a baseline content version per topic (so content_versions has data). */
    private function seedContentVersions(OutputInterface $output): void
    {
        if ($this->em->getRepository(ContentVersion::class)->count([]) > 0) {
            return;
        }
        $topics = $this->em->getRepository(Topic::class)->findAll();
        $count = 0;
        foreach ($topics as $topic) {
            $version = new ContentVersion('Topic', (int) $topic->getId(), 1, $topic->getApprovalStatus(), 'seed');
            $version->setFromStatus(null);
            $version->setSnapshot($topic->toArray());
            $version->setNote('Baseline version created during seed.');
            $this->em->persist($version);
            $count++;
        }
        $this->em->flush();
        if ($count > 0) {
            $output->writeln("  + {$count} content versions");
        }
    }

    /** Seed the audit_logs table so every table carries representative data. */
    private function seedAuditLogs(array $users, OutputInterface $output): void
    {
        if ($this->em->getRepository(AuditLog::class)->count([]) > 0) {
            return;
        }

        $super = $users['surdbells@gmail.com'] ?? null;
        $admin = $users['school@gmail.com'] ?? null;

        $entries = [
            ['auth.login', $super, 'User', (string) $super?->getId()],
            ['institution.onboard', $super, 'Institution', (string) $admin?->getInstitution()?->getId()],
            ['auth.login', $admin, 'User', (string) $admin?->getId()],
            ['user.profile.update', $admin, 'User', (string) $admin?->getId()],
        ];

        foreach ($entries as [$action, $actor, $objType, $objId]) {
            $log = new AuditLog($action);
            $log->setUserId($actor?->getId());
            $log->setInstitutionId($actor?->getInstitution()?->getId());
            $log->setObject($objType, $objId);
            $log->setIpDevice('127.0.0.1 seed');
            $this->em->persist($log);
        }
        $this->em->flush();
        $output->writeln('  + ' . count($entries) . ' audit log entries');
    }
}
