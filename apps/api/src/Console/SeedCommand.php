<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Entity\AcademicSession;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\AssessmentQuestion;
use App\Domain\Entity\AttemptAnswer;
use App\Domain\Entity\AuditLog;
use App\Domain\Entity\Announcement;
use App\Domain\Entity\CatalogSubject;
use App\Domain\Entity\ContentPackage;
use App\Domain\Entity\ContentResource;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Message;
use App\Domain\Entity\SupportMessage;
use App\Domain\Entity\SupportTicket;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\FeedbackNote;
use App\Domain\Entity\GuardianLink;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Intervention;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\LiveClassAttendance;
use App\Domain\Entity\Notification;
use App\Domain\Entity\Permission;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\BillingTransaction;
use App\Domain\Entity\Question;
use App\Domain\Entity\Report;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\Role;
use App\Domain\Entity\RolePermission;
use App\Domain\Entity\SafeguardingCase;
use App\Domain\Entity\SchemeOfWork;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\Term;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Entity\TopicProgress;
use App\Domain\Entity\TutorQuestion;
use App\Domain\Entity\TutorRating;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Entity\WorksheetQuestion;
use App\Domain\Entity\WorksheetResponse;
use App\Domain\Entity\WorksheetSubmission;
use App\Domain\Lifecycle;
use App\Service\AnswerGrader;
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
        private readonly AnswerGrader $grader,
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
        $this->seedAssessments($output);
        $this->seedAttempts($output);
        $this->seedWorksheets($output);
        $this->seedPortfolio($output);
        $this->seedFeedback($output);
        $this->seedLiveClasses($output);
        $this->seedGuardians($output);
        $this->seedBilling($output);
        $this->seedCatalogSubjects($output);
        $this->seedContent($output);
        $this->seedSupport($output);
        $this->seedMessaging($output);
        $this->seedCustomRole($output);
        $this->seedAskTutor($output);
        $this->seedNotifications($output);
        $this->seedTopicProgress($output);
        $this->seedInterventions($output);
        $this->seedSafeguarding($output);
        $this->seedReports($output);
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
                $pack->setVideoUrl('https://www.youtube.com/watch?v=OmJ-4B-mS-Y'); // sample lesson video, embedded in Learn
                $pack->setMedia([
                    ['url' => 'https://www.youtube.com/watch?v=OmJ-4B-mS-Y', 'name' => 'Lesson video'],
                    ['url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3', 'name' => 'Audio recap'],
                    ['url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf', 'name' => 'Reference PDF'],
                ]);
                $pack->setWorkedExamples("Worked example: step through a typical " . $title . " problem.");
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
        $all = ['view' => true, 'create' => true, 'edit' => true, 'approve' => true, 'export' => true, 'delete' => true, 'archive' => true];
        $rw = ['view' => true, 'create' => true, 'edit' => true];
        $ro = ['view' => true];
        $appr = ['view' => true, 'approve' => true, 'archive' => true]; // approver of governed content may also archive/take down

        $matrix = [
            Role::SCHOOL_ADMIN => [
                'academic_setup' => $all, 'user' => $rw, 'curriculum_pack' => $ro, 'delivery_pack' => $appr,
                'assessment' => $appr, 'gradebook' => ['view' => true, 'approve' => true, 'export' => true],
                'report' => ['view' => true, 'export' => true], 'intervention' => $rw, 'resource' => $appr,
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

    /** Seed one safeguarding case (reported by a teacher, under review). */
    private function seedSafeguarding(OutputInterface $output): void
    {
        if ($this->em->getRepository(SafeguardingCase::class)->count([]) > 0) {
            return;
        }
        $find = fn (string $email) => $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $teacher = $find('teacher@gmail.com');
        $lead = $find('school@gmail.com');
        $student = $find('student3@gmail.com');
        if ($teacher === null) {
            return;
        }
        $case = new SafeguardingCase('Student appears withdrawn and has missed several live classes.');
        $case->setReportedBy($teacher);
        $case->setInstitution($teacher->getInstitution());
        $case->setStudent($student);
        $case->setCategory('welfare');
        $case->setDetails('Noticed over the past two weeks; also reflected in the attendance data.');
        $case->setStatus(SafeguardingCase::UNDER_REVIEW);
        $case->setHandledBy($lead);
        $this->em->persist($case);
        $this->em->flush();
        $output->writeln('  + 1 safeguarding case');
    }

    /** Seed interventions off the seeded low quiz scores. */
    private function seedInterventions(OutputInterface $output): void
    {
        if ($this->em->getRepository(Intervention::class)->count([]) > 0) {
            return;
        }
        $find = fn (string $email) => $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $tunde = $find('student3@gmail.com');   // scored 0% on the quiz
        $chiamaka = $find('student2@gmail.com'); // scored 50%
        $teacher = $find('teacher@gmail.com');
        $lead = $find('school@gmail.com');
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        $subject = $topic?->getSubject();
        if ($tunde === null || $teacher === null) {
            return;
        }

        $one = new Intervention($tunde, 'Scored 0% on the Whole Numbers quiz — needs place-value support.');
        $one->setSubject($subject);
        $one->setTopic($topic);
        $one->setRaisedBy($lead);
        $one->setAssignedTo($teacher);
        $one->setStatus(Intervention::IN_PROGRESS);
        $one->setPriority('high');
        $one->setType('Small Group Remediation');
        $one->setProgress(60);
        $one->setDueDate(new DateTimeImmutable('+5 days'));
        $this->em->persist($one);

        $count = 1;
        if ($chiamaka !== null) {
            $two = new Intervention($chiamaka, 'Borderline pass (50%) — monitor and revisit fractions.');
            $two->setSubject($subject);
            $two->setRaisedBy($teacher);
            $two->setAssignedTo($teacher);
            $two->setStatus(Intervention::IN_PROGRESS);
            $two->setPriority('medium');
            $two->setType('Targeted Quiz Support');
            $two->setProgress(40);
            $two->setDueDate(new DateTimeImmutable('-2 days')); // overdue follow-up
            $this->em->persist($two);
            $count++;

            // An overdue attendance case (high priority) + a resolved case, for the tabs/KPIs.
            $three = new Intervention($chiamaka, 'Missing several homework submissions this term.');
            $three->setSubject($subject);
            $three->setRaisedBy($lead);
            $three->setAssignedTo($teacher);
            $three->setStatus(Intervention::OPEN);
            $three->setPriority('high');
            $three->setType('Attendance Support Plan');
            $three->setProgress(15);
            $three->setDueDate(new DateTimeImmutable('-1 day'));
            $this->em->persist($three);
            $count++;
        }

        $four = new Intervention($tunde, 'Reading fluency below expected level — phonics support.');
        $four->setSubject($subject);
        $four->setRaisedBy($teacher);
        $four->setAssignedTo($teacher);
        $four->setStatus(Intervention::RESOLVED);
        $four->setPriority('medium');
        $four->setType('Reading Intervention');
        $four->setProgress(100);
        $four->setOutcome('Reading level improved to age-appropriate after six weeks of support.');
        $four->setResolvedAt(new DateTimeImmutable('-3 days'));
        $four->setDueDate(new DateTimeImmutable('-7 days'));
        $this->em->persist($four);
        $count++;

        $this->em->flush();
        $output->writeln("  + {$count} interventions");
    }

    /** Seed lesson-viewed progress so a student is mid-journey. */
    private function seedTopicProgress(OutputInterface $output): void
    {
        if ($this->em->getRepository(TopicProgress::class)->count([]) > 0) {
            return;
        }
        $student = $this->em->getRepository(User::class)->findOneBy(['email' => 'student@gmail.com']);
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        if ($student === null || $topic === null) {
            return;
        }
        $progress = new TopicProgress($topic, $student);
        $progress->setLessonViewed(true);
        $progress->setLessonViewedAt(new DateTimeImmutable('-3 days'));
        $this->em->persist($progress);
        $this->em->flush();
        $output->writeln('  + 1 topic progress (lesson viewed)');
    }

    /** Seed a few in-app notifications so the inbox has content. */
    private function seedNotifications(OutputInterface $output): void
    {
        if ($this->em->getRepository(Notification::class)->count([]) > 0) {
            return;
        }
        $find = fn (string $email) => $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        // [email, type, title, message, link, read]
        $defs = [
            ['student3@gmail.com', 'feedback', 'New feedback from Ngozi Okafor', 'You mixed up place value in Q3 — remember the hundreds column. Redo questions 3 and 7.', '/student/academics/feedback', false],
            ['student2@gmail.com', 'grade', 'Worksheet graded: Whole Numbers — Practice Worksheet', 'You scored 8/10. Good work — revisit place value in Q3 and Q7.', '/student/academics/worksheets', false],
            ['student@gmail.com', 'live', 'Live class starting soon', 'Fractions — Live Q&A begins shortly. Join from your dashboard.', '/student/academics/live-classes', true],
            ['school@gmail.com', 'billing', 'Payment received — Standard plan', 'We received your payment of ₦35,000. Your subscription is active.', '/admin/management/billing', true],
        ];
        $count = 0;
        foreach ($defs as [$email, $type, $title, $message, $link, $read]) {
            $user = $find($email);
            if ($user === null) {
                continue;
            }
            $n = new Notification($user, $type, $title);
            $n->setMessage($message);
            $n->setLink($link);
            $n->setRead($read);
            if ($read) {
                $n->setReadAt(new DateTimeImmutable('-2 hours'));
            }
            $this->em->persist($n);
            $count++;
        }
        $this->em->flush();
        $output->writeln("  + {$count} notifications");
    }

    /** Seed subscription plans + an active subscription with a paid invoice. */
    private function seedBilling(OutputInterface $output): void
    {
        if ($this->em->getRepository(SubscriptionPlan::class)->count([]) > 0) {
            return;
        }
        // [code, name, price kobo, interval, maxStudents, maxTeachers, features, modules]
        $defs = [
            ['starter', 'Starter', 1500000, 'termly', 200, 20,
                ['Core LMS', 'Quizzes & worksheets', 'Basic reports'],
                ['worksheets']],
            ['standard', 'Standard', 3500000, 'termly', 600, 60,
                ['Everything in Starter', 'Live classes', 'Portfolio & analytics'],
                ['assessments', 'worksheets', 'portfolio', 'live_classes', 'analytics', 'interventions']],
            ['premium', 'Premium', 6000000, 'termly', null, null,
                ['Everything in Standard', 'Unlimited seats', 'Priority support'],
                SubscriptionPlan::MODULES],
        ];
        $plans = [];
        foreach ($defs as [$code, $name, $price, $interval, $maxS, $maxT, $features, $modules]) {
            $plan = new SubscriptionPlan($code, $name, $price);
            $plan->setInterval($interval);
            $plan->setMaxStudents($maxS);
            $plan->setMaxTeachers($maxT);
            $plan->setFeatures($features);
            $plan->setModules($modules);
            $plan->setDescription($name . ' plan billed per school term.');
            $this->em->persist($plan);
            $plans[$code] = $plan;
        }
        $this->em->flush();

        // Put GOF College on an active Standard subscription with a paid invoice.
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'school@gmail.com']);
        $institution = $admin?->getInstitution();
        if ($institution === null) {
            $output->writeln('  + 3 plans');
            return;
        }
        $standard = $plans['standard'];
        $now = new DateTimeImmutable();
        $start = $now->modify('-30 days');
        $end = $start->add($standard->periodInterval());
        $sub = new Subscription($institution, $standard, $start, $end);
        $this->em->persist($sub);

        $txn = new BillingTransaction($institution, $standard, 'LEARNO-SEED000001', $standard->getPriceKobo());
        $txn->setInitiatedBy($admin);
        $txn->setStatus(BillingTransaction::SUCCESS);
        $txn->setChannel('card');
        $txn->setPaidAt($start);
        $this->em->persist($txn);
        $this->em->flush();

        $output->writeln('  + 3 plans, 1 active subscription (Standard) + paid invoice');
    }

    /** Seed the platform subject catalogue (full Nigerian curriculum) and link school subjects. */
    private function seedCatalogSubjects(OutputInterface $output): void
    {
        // [name, code, level] — the NERDC junior + senior secondary curriculum.
        $defs = [
            // Junior secondary (JSS) core
            ['Mathematics', 'MTH', 'Junior/Senior'],
            ['English Language', 'ENG', 'Junior/Senior'],
            ['English Studies', 'ENGS', 'Junior'],
            ['Basic Science', 'BSC', 'Junior'],
            ['Basic Technology', 'BTECH', 'Junior'],
            ['Social Studies', 'SOS', 'Junior'],
            ['Civic Education', 'CIV', 'Junior/Senior'],
            ['Cultural and Creative Arts', 'CCA', 'Junior'],
            ['Business Studies', 'BST', 'Junior'],
            ['Agricultural Science', 'AGRIC', 'Junior/Senior'],
            ['Home Economics', 'HEC', 'Junior'],
            ['Computer Studies / ICT', 'COMP', 'Junior'],
            ['Physical and Health Education', 'PHE', 'Junior/Senior'],
            ['Christian Religious Studies', 'CRS', 'Junior/Senior'],
            ['Islamic Religious Studies', 'IRS', 'Junior/Senior'],
            ['French', 'FRE', 'Junior/Senior'],
            ['Arabic', 'ARB', 'Junior/Senior'],
            ['Hausa', 'HAU', 'Junior/Senior'],
            ['Igbo', 'IGB', 'Junior/Senior'],
            ['Yoruba', 'YOR', 'Junior/Senior'],
            ['History', 'HIS', 'Junior/Senior'],
            // Senior secondary (SSS) sciences
            ['Biology', 'BIO', 'Senior'],
            ['Chemistry', 'CHE', 'Senior'],
            ['Physics', 'PHY', 'Senior'],
            ['Further Mathematics', 'FMTH', 'Senior'],
            ['Agricultural Science (SSS)', 'AGRICS', 'Senior'],
            ['Health Education', 'HED', 'Senior'],
            ['Computer Science', 'CSC', 'Senior'],
            ['Technical Drawing', 'TDR', 'Senior'],
            ['Food and Nutrition', 'FDN', 'Senior'],
            // Senior secondary humanities & commercial
            ['Literature-in-English', 'LIT', 'Senior'],
            ['Government', 'GOV', 'Senior'],
            ['Economics', 'ECO', 'Senior'],
            ['Geography', 'GEO', 'Senior'],
            ['Commerce', 'COM', 'Senior'],
            ['Financial Accounting', 'ACC', 'Senior'],
            ['Marketing', 'MKT', 'Senior'],
            ['Fine Arts', 'ART', 'Senior'],
            ['Music', 'MUS', 'Senior'],
        ];

        $repo = $this->em->getRepository(CatalogSubject::class);
        $byCode = [];
        $added = 0;
        foreach ($defs as [$name, $code, $level]) {
            $existing = $repo->findOneBy(['code' => $code]);
            if ($existing !== null) {
                $byCode[$code] = $existing;
                continue;
            }
            $c = new CatalogSubject($name, $code);
            $c->setCurriculum('NERDC');
            $c->setDescription($name . ' — Nigerian ' . strtolower($level) . ' secondary curriculum.');
            $this->em->persist($c);
            $byCode[$code] = $c;
            $added++;
        }
        $this->em->flush();

        // Link the seeded institution subjects to their catalogue entry by code.
        foreach ($this->em->getRepository(Subject::class)->findAll() as $subject) {
            $code = strtoupper((string) ($subject->toArray()['code'] ?? ''));
            if (isset($byCode[$code]) && $subject->getCatalogSubject() === null) {
                $subject->setCatalogSubject($byCode[$code]);
            }
        }
        $this->em->flush();

        $output->writeln('  + ' . $added . ' catalogue subjects added (' . count($defs) . ' in the Nigerian curriculum)');
    }

    /** Seed the platform content library + one bundled package. */
    private function seedContent(OutputInterface $output): void
    {
        if ($this->em->getRepository(ContentResource::class)->count([]) > 0) {
            return;
        }
        $superAdmin = $this->em->getRepository(User::class)->findOneBy(['email' => 'surdbells@gmail.com']);

        // [title, type, subject, grade, licence, source, premium]
        $defs = [
            ['Whole Numbers — teaching slides', 'document', 'Mathematics', 'JSS 1', 'owned', null, false],
            ['LCM & HCF worked examples (video)', 'video', 'Mathematics', 'JSS 1', 'cc-by', 'Khan Academy', false],
            ['Fractions practice set', 'assignment', 'Mathematics', 'JSS 1', 'owned', null, false],
            ['Basic Operations interactive drill', 'interactive', 'Mathematics', 'JSS 1', 'licensed', 'GeoGebra', true],
            ['Reading comprehension pack', 'document', 'English', 'JSS 1', 'owned', null, false],
        ];
        $resources = [];
        foreach ($defs as [$title, $type, $subject, $grade, $licence, $source, $premium]) {
            $r = new ContentResource($title, $type);
            $r->setSubjectArea($subject);
            $r->setGradeLevel($grade);
            $r->setLicence($licence);
            $r->setSource($source);
            $r->setIsPremium($premium);
            $r->setDescription($title . ' for the ' . $grade . ' ' . $subject . ' curriculum.');
            $r->setCreatedBy($superAdmin);
            $this->em->persist($r);
            $resources[] = $r;
        }
        $this->em->flush();

        $pack = new ContentPackage('JSS 1 Mathematics — Term 1', 'subject_pack');
        $pack->setSubjectArea('Mathematics');
        $mathCatalog = $this->em->getRepository(CatalogSubject::class)->findOneBy(['code' => 'MTH']);
        if ($mathCatalog !== null) {
            $pack->setCatalogSubject($mathCatalog);
        }
        $pack->setGradeLevel('JSS 1');
        $pack->setDescription('Everything needed to teach JSS 1 Mathematics for the first term.');
        $pack->setPriceKobo(2500000);
        $pack->setDurationMonths(4);
        foreach (array_slice($resources, 0, 4) as $r) {
            $pack->addResource($r);
        }
        $this->em->persist($pack);
        $this->em->flush();

        // Assign the pack to the seeded school so its resources are visible there.
        $gof = $this->em->getRepository(User::class)->findOneBy(['email' => 'school@gmail.com'])?->getInstitution();
        if ($gof !== null) {
            $gof->setAssignedPackageId($pack->getId());
            $this->em->flush();
        }

        $output->writeln('  + 5 content resources, 1 content package (assigned to GOF College)');
    }

    /** Seed a couple of support tickets so the support centre isn't empty. */
    private function seedSupport(OutputInterface $output): void
    {
        if ($this->em->getRepository(SupportTicket::class)->count([]) > 0) {
            return;
        }
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'school@gmail.com']);
        $superAdmin = $this->em->getRepository(User::class)->findOneBy(['email' => 'surdbells@gmail.com']);
        if ($admin === null) {
            return;
        }

        // An open, unanswered ticket.
        $t1 = new SupportTicket('Cannot publish an assessment', $admin, 'LEARNO-TKT-000001');
        $t1->setCategory('technical');
        $t1->setPriority('high');
        $t1->addMessage(new SupportMessage($t1, $admin, 'When I try to publish a quiz it says a question is not validated, but they all look validated.', false));
        $this->em->persist($t1);

        // A ticket that support has answered and resolved.
        $t2 = new SupportTicket('Invoice for last term', $admin, 'LEARNO-TKT-000002');
        $t2->setCategory('billing');
        $t2->setPriority('normal');
        $t2->addMessage(new SupportMessage($t2, $admin, 'Please can I get the invoice for our last term subscription?', false));
        if ($superAdmin !== null) {
            $t2->addMessage(new SupportMessage($t2, $superAdmin, 'Your invoice is under Billing → Transactions. Let us know if you need a PDF copy.', true));
            $t2->markStaffResponded();
            $t2->setAssignedTo($superAdmin);
        }
        $t2->setStatus(SupportTicket::RESOLVED);
        $this->em->persist($t2);

        $this->em->flush();
        $output->writeln('  + 2 support tickets');
    }

    /** Seed a short teacher↔student thread and a couple of announcements. */
    private function seedMessaging(OutputInterface $output): void
    {
        if ($this->em->getRepository(Announcement::class)->count([]) > 0) {
            return;
        }
        $repo = $this->em->getRepository(User::class);
        $admin = $repo->findOneBy(['email' => 'school@gmail.com']);
        $teacher = $repo->findOneBy(['email' => 'teacher@gmail.com']);
        $student = $repo->findOneBy(['email' => 'student@gmail.com']);
        $institution = $admin?->getInstitution();
        if ($institution === null) {
            return;
        }

        if ($teacher !== null && $student !== null) {
            $m1 = new Message($institution, $teacher, $student, 'Hi, please remember to submit your fractions worksheet by Friday.');
            $m1->markRead();
            $this->em->persist($m1);
            $this->em->persist(new Message($institution, $student, $teacher, 'Okay sir, I will submit it today.'));
        }

        // Recipient counts per audience (real, from the seeded institution).
        $countFor = function (string $audience) use ($institution): int {
            $roles = Announcement::rolesForAudience($audience);
            return (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
                ->where('u.institution = :i')->andWhere('r.code IN (:roles)')
                ->setParameter('i', $institution)->setParameter('roles', $roles)
                ->getQuery()->getSingleScalarResult();
        };
        $firstClass = $this->em->getRepository(SchoolClass::class)->findOneBy(['institution' => $institution]);

        // [title, body, audience, category, priority, status, priorityDaysAgo|scheduleInDays, channels]
        $specs = [
            ['Mid-Year Holiday Notice', 'The school will close for the mid-year holiday on Friday and resume the following Monday. Please travel safely.', 'all', 'general', 'medium', 'sent', 6, ['in_app' => true, 'email' => true, 'parent_copy' => false]],
            ['Third-Term Assessment Reminder', 'Third-term assessments begin next week. Please revise all topics covered so far and bring the required materials.', 'students', 'academics', 'high', 'sent', 4, ['in_app' => true, 'email' => true, 'parent_copy' => false]],
            ['PTA Meeting — Saturday 10am', 'Dear parents, the termly PTA meeting holds this Saturday at 10am in the main hall. Your attendance is important.', 'parents', 'events', 'medium', 'sent', 3, ['in_app' => true, 'email' => true, 'parent_copy' => true]],
            ['Teacher Briefing — Third-Term Plan', 'All teaching staff: please meet in the staff room at 8am tomorrow to review the third-term instructional plan.', 'staff', 'internal', 'medium', 'sent', 2, ['in_app' => true, 'email' => false, 'parent_copy' => false]],
            ['School Assembly — Friday 8am', 'A whole-school assembly holds this Friday at 8am. All students and staff should be seated by 7:50am.', 'all', 'general', 'low', 'sent', 1, ['in_app' => true, 'email' => false, 'parent_copy' => false]],
            ['Attendance Follow-up — Term 3 Week 4', '', 'parents', 'attendance', 'medium', 'draft', 0, ['in_app' => true, 'email' => true, 'parent_copy' => true]],
            ['Class Assembly Reminder', 'A reminder that your class assembly holds next week. Please be prompt and in full uniform.', 'class', 'reminder', 'medium', 'scheduled', 5, ['in_app' => true, 'email' => false, 'parent_copy' => false]],
        ];
        $author = $teacher ?? $admin;
        foreach ($specs as [$title, $text, $audience, $category, $priority, $status, $days, $channels]) {
            $a = new Announcement($institution, $audience === 'staff' || $audience === 'all' ? $admin : $author, $title, $text, $audience);
            $a->setCategory($category);
            $a->setPriority($priority);
            $a->setChannels($channels);
            if ($audience === 'class' && $firstClass !== null) {
                $a->setSchoolClass($firstClass);
                $a->setSubjectName('Mathematics');
            }
            if ($status === 'sent') {
                $a->setStatus(Announcement::SENT);
                $a->setSentAt(new DateTimeImmutable("-{$days} days"));
                $a->setRecipientCount($audience === 'class' ? 3 : $countFor($audience));
            } elseif ($status === 'scheduled') {
                $a->setStatus(Announcement::SCHEDULED);
                $a->setScheduledAt(new DateTimeImmutable("+{$days} days"));
            } else {
                $a->setStatus(Announcement::DRAFT);
            }
            $this->em->persist($a);
        }
        $this->em->flush();

        $output->writeln('  + 2 messages, ' . count($specs) . ' announcements (sent/scheduled/draft)');
    }

    /** Seed one institution-scoped custom role so Roles & Permissions demos a custom row. */
    private function seedCustomRole(OutputInterface $output): void
    {
        $school = $this->em->getRepository(Institution::class)->findOneBy(['name' => 'GOF College'])
            ?? $this->em->getRepository(Institution::class)->findOneBy([]);
        if ($school === null || $this->em->getRepository(Role::class)->findOneBy(['institution' => $school]) !== null) {
            return;
        }
        $role = new Role('c' . $school->getId() . '_vice_principal_academics', 'Vice Principal Academics', 'school', false);
        $role->setInstitution($school);
        $role->setDescription('Academic leadership — oversees assessments, gradebook and reports.');
        $this->em->persist($role);

        $grants = [
            'assessment' => ['view' => true, 'create' => true, 'edit' => true, 'approve' => true],
            'worksheet' => ['view' => true, 'create' => true, 'edit' => true],
            'gradebook' => ['view' => true, 'edit' => true, 'export' => true],
            'report' => ['view' => true, 'export' => true],
            'delivery_pack' => ['view' => true, 'approve' => true],
        ];
        foreach ($grants as $code => $actions) {
            $this->em->persist(new RolePermission($role, $code, $actions, 'school'));
        }
        $this->em->flush();
        $output->writeln('  + 1 custom role (Vice Principal Academics)');
    }

    /** Seed Ask Tutor — one answered question, one open, and a tutor rating. */
    private function seedAskTutor(OutputInterface $output): void
    {
        if ($this->em->getRepository(TutorQuestion::class)->count([]) > 0) {
            return;
        }
        $repo = $this->em->getRepository(User::class);
        $student = $repo->findOneBy(['email' => 'student@gmail.com']);
        $teacher = $repo->findOneBy(['email' => 'teacher@gmail.com']);
        $maths = $this->em->getRepository(Subject::class)->findOneBy(['name' => 'Mathematics']);
        if ($student === null || $teacher === null) {
            return;
        }

        $answered = new TutorQuestion($student, 'How do I find the LCM of 4 and 6? I keep getting confused with the multiples.');
        $answered->setTutor($teacher);
        $answered->setSubject($maths);
        $answered->answer('List the multiples of each: 4 → 4, 8, 12… and 6 → 6, 12… The first common one is 12, so LCM(4,6) = 12. Try it with 3 and 5 next!', $teacher);
        $this->em->persist($answered);

        $open = new TutorQuestion($student, 'For place value, how do I know which digit is in the hundreds column?');
        $open->setTutor($teacher);
        $open->setSubject($maths);
        $this->em->persist($open);

        $rating = new TutorRating($student, $teacher, 5);
        $rating->setComment('Explains things really clearly and patiently.');
        $this->em->persist($rating);

        $this->em->flush();
        $output->writeln('  + Ask Tutor: 2 questions (1 answered) + 1 rating');
    }

    /** Link the seeded parent account to a student so the parent report works. */
    private function seedGuardians(OutputInterface $output): void
    {
        if ($this->em->getRepository(GuardianLink::class)->count([]) > 0) {
            return;
        }
        $find = fn (string $email) => $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $parent = $find('parent@gmail.com');
        $student = $find('student@gmail.com');
        if ($parent === null || $student === null) {
            return;
        }
        $this->em->persist(new GuardianLink($parent, $student, 'parent'));
        $this->em->flush();
        $output->writeln('  + 1 guardian link (parent@ → student@)');
    }

    /** Seed live classes — one scheduled, one live with an attendee. */
    private function seedLiveClasses(OutputInterface $output): void
    {
        if ($this->em->getRepository(LiveClass::class)->count([]) > 0) {
            return;
        }
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        if ($topic === null) {
            return;
        }
        $subject = $topic->getSubject();
        $class = $topic->getSchoolClass();
        $institution = $subject->getInstitution();
        // Prefer the primary demo teacher so their dashboard shows today's schedule.
        $teacher = $this->em->getRepository(User::class)->findOneBy(['email' => 'teacher@gmail.com'])
            ?? $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
                ->where('r.code = :t')->andWhere('u.institution = :i')
                ->setParameter('t', 'teacher')->setParameter('i', $institution)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        // Agora channel name for a class — no URL (the client joins the channel by name).
        $channel = static fn (string $title): string => 'learno-' . substr(md5($title), 0, 16);

        // A scheduled class (tomorrow) and one currently live.
        $scheduled = new LiveClass($subject, 'Whole Numbers — Live Revision', new DateTimeImmutable('tomorrow 10:00'));
        $scheduled->setSchoolClass($class);
        $scheduled->setTopic($topic);
        $scheduled->setHost($teacher);
        $scheduled->setDurationMinutes(45);
        $scheduled->setRoomName($channel('Whole Numbers Revision'));
        $scheduled->setStatus(LiveClass::SCHEDULED);
        $this->em->persist($scheduled);

        // A second class starting soon (today). Left SCHEDULED (not pre-LIVE): going
        // live through the app assigns a fresh channel so each session is clean.
        $live = new LiveClass($subject, 'Fractions — Live Q&A', new DateTimeImmutable('+30 minutes'));
        $live->setSchoolClass($class);
        $live->setHost($teacher);
        $live->setDurationMinutes(30);
        $live->setRoomName($channel('Fractions Live Q and A'));
        $live->setStatus(LiveClass::SCHEDULED);
        $this->em->persist($live);

        // A class currently LIVE (for the learner "join now" / hero LIVE state).
        $liveNow = new LiveClass($subject, 'Whole Numbers — Operations', new DateTimeImmutable('-10 minutes'));
        $liveNow->setSchoolClass($class);
        $liveNow->setTopic($topic);
        $liveNow->setHost($teacher);
        $liveNow->setDurationMinutes(45);
        $liveNow->setRoomName($channel('Whole Numbers Operations Live'));
        $liveNow->setStatus(LiveClass::LIVE);
        $this->em->persist($liveNow);

        // Two past (ended) classes — one the student attended, one missed.
        $pastAttended = new LiveClass($subject, 'Place Value & Rounding', new DateTimeImmutable('-2 days 10:00'));
        $pastAttended->setSchoolClass($class);
        $pastAttended->setHost($teacher);
        $pastAttended->setDurationMinutes(55);
        $pastAttended->setStatus(LiveClass::ENDED);
        $this->em->persist($pastAttended);

        $pastMissed = new LiveClass($subject, 'Number Patterns & Sequences', new DateTimeImmutable('-5 days 10:00'));
        $pastMissed->setSchoolClass($class);
        $pastMissed->setHost($teacher);
        $pastMissed->setDurationMinutes(47);
        $pastMissed->setStatus(LiveClass::ENDED);
        $this->em->persist($pastMissed);
        $this->em->flush();

        // Prefer the primary demo learner so the "Attended" state shows for student@.
        $student = $this->em->getRepository(User::class)->findOneBy(['email' => 'student@gmail.com'])
            ?? $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
                ->where('r.code = :s')->andWhere('u.institution = :i')
                ->setParameter('s', 'student')->setParameter('i', $institution)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($student !== null) {
            $this->em->persist(new LiveClassAttendance($liveNow, $student, new DateTimeImmutable('-8 minutes')));
            $this->em->persist(new LiveClassAttendance($pastAttended, $student, new DateTimeImmutable('-2 days 10:02')));
            $this->em->flush();
        }
        $output->writeln('  + 5 live classes (scheduled/live/ended, 2 attendances)');
    }

    /** Seed teacher feedback notes (a correction and a praise) closing the loop. */
    private function seedFeedback(OutputInterface $output): void
    {
        if ($this->em->getRepository(FeedbackNote::class)->count([]) > 0) {
            return;
        }
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        $institution = $topic?->getSubject()->getInstitution();
        if ($institution === null) {
            return;
        }
        $teacher = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :t')->andWhere('u.institution = :i')
            ->setParameter('t', 'teacher')->setParameter('i', $institution)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $students = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :s')->andWhere('u.institution = :i')
            ->setParameter('s', 'student')->setParameter('i', $institution)
            ->setMaxResults(2)->getQuery()->getResult();
        if (empty($students)) {
            return;
        }

        // Structured feedback breakdowns (design: Feedback_LD) — teacher-authored.
        $notes = [
            [
                'student' => 0, 'type' => 'correction', 'topic' => $topic, 'ack' => false,
                'source_type' => 'worksheet', 'source_title' => 'Operations on Whole Numbers Worksheet',
                'subject' => 'Mathematics', 'score' => 72,
                'message' => 'Good work! You understand the basics well. Focus on aligning numbers by place value.',
                'strengths' => 'You added and subtracted whole numbers accurately, and showed good understanding of place value in most questions.',
                'practice_needed' => 'Check your place value alignment in subtraction, and show your working clearly for multi-step problems.',
                'common_error' => 'Place value confusion in subtraction — some answers were off by tens or hundreds due to incorrect borrowing.',
                'next_step' => 'Revise place value and subtraction with borrowing; redo questions 4–6 and attempt the follow-up quiz.',
                'focus' => [['Accuracy', 76], ['Showing Working', 64], ['Question Interpretation', 58], ['Problem Solving', 72]],
            ],
            [
                'student' => 0, 'type' => 'praise', 'topic' => null, 'ack' => true,
                'source_type' => 'quiz', 'source_title' => 'Estimation Quiz', 'subject' => 'Mathematics', 'score' => 85,
                'message' => 'Nice work! Your estimation skills are strong — keep practising to stay sharp.',
                'strengths' => 'Strong number sense and quick, accurate rounding.',
                'practice_needed' => null, 'common_error' => null, 'next_step' => 'Try the harder estimation set next.',
                'focus' => [['Accuracy', 88], ['Problem Solving', 80]],
            ],
            [
                'student' => 1, 'type' => 'praise', 'topic' => null, 'ack' => true,
                'source_type' => 'worksheet', 'source_title' => 'Whole Numbers — Practice Worksheet',
                'subject' => 'Mathematics', 'score' => 80,
                'message' => 'Excellent improvement this week — your working is much clearer. Keep it up!',
                'strengths' => 'Clear, well-ordered working and accurate addition.',
                'practice_needed' => 'Watch the ordering of 6-digit numbers.',
                'common_error' => null, 'next_step' => 'Revise Greater Than / Less Than before the next class.',
                'focus' => [['Accuracy', 82], ['Showing Working', 85]],
            ],
        ];
        foreach ($notes as $n) {
            if (!isset($students[$n['student']])) {
                continue;
            }
            $note = new FeedbackNote($students[$n['student']], $n['message']);
            $note->setAuthor($teacher);
            $note->setType($n['type']);
            $note->setTopic($n['topic']);
            $note->setSourceType($n['source_type']);
            $note->setSourceTitle($n['source_title']);
            $note->setSubjectName($n['subject']);
            $note->setScore($n['score']);
            $note->setStrengths($n['strengths']);
            $note->setPracticeNeeded($n['practice_needed']);
            $note->setCommonError($n['common_error']);
            $note->setNextStep($n['next_step']);
            $note->setFocusAreas(array_map(static fn ($f) => ['label' => $f[0], 'score' => $f[1]], $n['focus']));
            if ($n['ack']) {
                $note->setAcknowledged(true);
                $note->setAcknowledgedAt(new DateTimeImmutable('-6 hours'));
            }
            $this->em->persist($note);
        }
        $this->em->flush();
        $output->writeln('  + ' . count($notes) . ' feedback notes');
    }

    /** Seed portfolio evidence — one reviewed, one pending — for the competency track. */
    private function seedPortfolio(OutputInterface $output): void
    {
        if ($this->em->getRepository(PortfolioEntry::class)->count([]) > 0) {
            return;
        }
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        if ($topic === null) {
            return;
        }
        $reviewer = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :t')->andWhere('u.institution = :i')
            ->setParameter('t', 'teacher')->setParameter('i', $topic->getSubject()->getInstitution())
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $students = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :s')->andWhere('u.institution = :i')
            ->setParameter('s', 'student')->setParameter('i', $topic->getSubject()->getInstitution())
            ->setMaxResults(2)->getQuery()->getResult();
        if (empty($students)) {
            return;
        }

        foreach ($students as $i => $student) {
            $entry = new PortfolioEntry(
                $topic,
                $student,
                'Counting money at the market',
                'I helped my mother count change at the market and used place value to check the totals were correct.'
            );
            $entry->setSubmittedAt(new DateTimeImmutable('-3 days'));
            if ($i === 0) {
                $entry->setCompetencyRating('proficient');
                $entry->setReviewerFeedback('Strong real-life application of place value. Try a larger transaction next time.');
                $entry->setReviewedBy($reviewer);
                $entry->setStatus(PortfolioEntry::REVIEWED);
                $entry->setReviewedAt(new DateTimeImmutable('-1 day'));
            } else {
                $entry->setStatus(PortfolioEntry::SUBMITTED);
            }
            $this->em->persist($entry);
        }
        $this->em->flush();
        $output->writeln('  + ' . count($students) . ' portfolio entries');
    }

    /** Seed a published worksheet with one graded and one pending submission. */
    private function seedWorksheets(OutputInterface $output): void
    {
        if ($this->em->getRepository(Worksheet::class)->count([]) > 0) {
            return;
        }
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        if ($topic === null) {
            return;
        }
        $worksheet = new Worksheet($topic, 'Whole Numbers — Practice Worksheet');
        $worksheet->setTrack('academic');
        $worksheet->setInstructions('Attempt all questions. Show your working where necessary. You can save your progress and return later.');
        $worksheet->setDueDate(new DateTimeImmutable('+7 days'));
        $worksheet->setApprovalStatus(Lifecycle::PUBLISHED);
        $this->em->persist($worksheet);
        $this->em->flush();

        // Structured questions across three sections (4 objective + 1 free-response).
        // [section, prompt, type, options|null, correctAnswer|null, marks]
        $spec = [
            ['Section A: Addition', '345 + 278 =', 'numeric', null, '623', 1],
            ['Section A: Addition', '6,789 + 2,345 =', 'numeric', null, '9134', 1],
            ['Section B: Concepts', 'Which of these is an even number?', 'mcq', ['7', '12', '19', '5'], '12', 1],
            ['Section B: Concepts', '12 is a prime number.', 'true_false', null, 'false', 1],
            ['Section C: Word Problems', 'A shopkeeper had 2,450 pencils and bought another 3,275. Show your working and give the total.', 'free_response', null, null, 2],
        ];
        $questions = [];
        $sectionPos = [];
        $total = 0;
        foreach ($spec as $i => [$section, $prompt, $type, $options, $correct, $marks]) {
            $q = new WorksheetQuestion($worksheet, $prompt);
            $sectionPos[$section] ??= count($sectionPos);
            $q->setSectionLabel($section);
            $q->setSectionPosition($sectionPos[$section]);
            $q->setPosition($i);
            $q->setType($type);
            $q->setOptions($options);
            $q->setCorrectAnswer($correct);
            $q->setMarks($marks);
            $this->em->persist($q);
            $questions[] = $q;
            $total += $marks;
        }
        $worksheet->setTotalMarks($total);
        $this->em->flush();

        $version = new ContentVersion('Worksheet', (int) $worksheet->getId(), 1, Lifecycle::PUBLISHED, 'seed');
        $version->setFromStatus(null);
        $version->setSnapshot($worksheet->toArray());
        $version->setNote('Baseline version created during seed.');
        $this->em->persist($version);

        $students = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :s')->andWhere('u.institution = :i')
            ->setParameter('s', 'student')->setParameter('i', $topic->getSubject()->getInstitution())
            ->setMaxResults(2)->getQuery()->getResult();

        // Per-student answers: [answer, awardedMarks|null, correct|null] aligned to $questions.
        // Student 0 — fully graded 6/6 (free-response teacher-marked).
        // Student 1 — submitted, auto-marked 3/6 (Q2 wrong), free-response still awaiting the teacher.
        $answerSets = [
            [['623', 1, true], ['9134', 1, true], ['12', 1, true], ['false', 1, true], ['2,450 + 3,275 = 5,725 pencils altogether.', 2, null]],
            [['623', 1, true], ['9999', 0, false], ['12', 1, true], ['false', 1, true], ['2450 add 3275 is 5725', null, null]],
        ];
        foreach ($students as $i => $student) {
            $set = $answerSets[$i] ?? $answerSets[0];
            $submission = new WorksheetSubmission($worksheet, $student);
            $submission->setSubmittedAt(new DateTimeImmutable('-1 day'));
            $this->em->persist($submission);
            $this->em->flush(); // id for responses

            $auto = 0;
            foreach ($questions as $qi => $q) {
                [$answer, $awarded, $correct] = $set[$qi];
                $resp = new WorksheetResponse($submission, $q);
                $resp->setAnswer($answer);
                $resp->setAwardedMarks($awarded);
                $resp->setCorrect($correct);
                $this->em->persist($resp);
                $auto += (int) ($awarded ?? 0);
            }

            if ($i === 0) {
                $submission->setScore($auto);
                $submission->setFeedback('Great working shown — correct total. Keep aligning your subtraction by place value.');
                $submission->setStatus(WorksheetSubmission::GRADED);
                $submission->setGradedAt(new DateTimeImmutable('-12 hours'));
            } else {
                $submission->setScore($auto); // auto-marked so far; free-response pending
                $submission->setStatus(WorksheetSubmission::SUBMITTED);
            }
            $this->em->persist($submission);
        }
        $this->em->flush();
        $output->writeln('  + 1 worksheet (' . count($questions) . ' questions, ' . count($students) . ' submissions)');
    }

    /** Seed graded attempts (pass, borderline, fail) so the gradebook has data. */
    private function seedAttempts(OutputInterface $output): void
    {
        if ($this->em->getRepository(AssessmentAttempt::class)->count([]) > 0) {
            return;
        }
        $quiz = $this->em->getRepository(Assessment::class)->findOneBy(['approvalStatus' => Lifecycle::PUBLISHED]);
        if ($quiz === null || $quiz->getItems()->count() === 0) {
            return;
        }
        $students = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :s')->andWhere('u.institution = :i')
            ->setParameter('s', 'student')->setParameter('i', $quiz->getSubject()->getInstitution())
            ->setMaxResults(3)->getQuery()->getResult();
        if (empty($students)) {
            return;
        }

        $items = array_values($quiz->getItems()->toArray());
        // How many of the questions each student answers correctly: full, half, none.
        $strategies = [count($items), (int) floor(count($items) / 2), 0];

        $count = 0;
        foreach ($students as $i => $student) {
            $correctUpTo = $strategies[$i] ?? 0;
            $attempt = new AssessmentAttempt($quiz, $student);
            $attempt->setTrack($quiz->getTrack());
            $attempt->setStartedAt(new DateTimeImmutable('-2 days'));
            $this->em->persist($attempt);

            $score = 0;
            $total = 0;
            foreach ($items as $idx => $item) {
                $question = $item->getQuestion();
                $total += $item->effectiveMarks();
                $response = $idx < $correctUpTo ? $this->correctResponse($question) : null;
                $graded = $this->grader->grade($question, $response);
                $awarded = $graded['correct'] ? $item->effectiveMarks() : 0;

                $answer = new AttemptAnswer($attempt, $question);
                $answer->setResponse($response);
                $answer->setCorrect($graded['correct']);
                $answer->setMarksAwarded($awarded);
                $this->em->persist($answer);
                $score += $awarded;
            }

            $percentage = $total > 0 ? round($score / $total * 100, 1) : 0.0;
            $attempt->setTotalMarks($total);
            $attempt->setScore($score);
            $attempt->setPercentage($percentage);
            $attempt->setPassed($percentage >= $quiz->getPassMark());
            $attempt->setStatus(AssessmentAttempt::GRADED);
            $attempt->setSubmittedAt(new DateTimeImmutable('-2 days'));
            $count++;
        }
        $this->em->flush();
        $output->writeln("  + {$count} graded attempts");
    }

    private function correctResponse(\App\Domain\Entity\Question $q): mixed
    {
        $answer = $q->getCorrectAnswer();
        return match ($q->getType()) {
            'short' => is_array($answer) ? ($answer[0] ?? '') : $answer,
            'numeric' => is_array($answer) ? ($answer['value'] ?? null) : $answer,
            default => $answer,
        };
    }

    /** Seed a published diagnostic quiz built from validated Whole Numbers questions. */
    private function seedAssessments(OutputInterface $output): void
    {
        if ($this->em->getRepository(Assessment::class)->count([]) > 0) {
            return;
        }
        $topic = $this->em->getRepository(Topic::class)->findOneBy(['title' => 'Whole Numbers']);
        if ($topic === null) {
            return;
        }
        $questions = $this->em->getRepository(Question::class)->findBy(['topic' => $topic]);
        $validated = array_values(array_filter($questions, static fn (Question $q) => $q->isAnswerValidated()));
        if (empty($validated)) {
            return;
        }

        $quiz = new Assessment($topic->getSubject(), 'Whole Numbers — Diagnostic Quiz');
        $quiz->setTopic($topic);
        $quiz->setType('quiz');
        $quiz->setTrack('academic');
        $quiz->setDurationMinutes(15);
        $quiz->setPassMark(50);
        $quiz->setInstructions('Answer all questions. Show your reasoning where asked.');
        $quiz->setApprovalStatus(Lifecycle::PUBLISHED);
        $this->em->persist($quiz);

        $pos = 0;
        foreach ($validated as $q) {
            $this->em->persist(new AssessmentQuestion($quiz, $q, $pos++));
        }
        $this->em->flush();

        $version = new ContentVersion('Assessment', (int) $quiz->getId(), 1, Lifecycle::PUBLISHED, 'seed');
        $version->setFromStatus(null);
        $version->setSnapshot($quiz->toArray());
        $version->setNote('Baseline version created during seed.');
        $this->em->persist($version);
        $this->em->flush();

        $output->writeln('  + 1 assessment (' . count($validated) . ' questions)');
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

    /**
     * Seed a couple of pre-generated platform reports so the super-admin Reports
     * list, view and export flows are testable without first generating one.
     * Every figure is a live count/aggregate of the rows already seeded above —
     * nothing here is fabricated; it mirrors exactly what ReportsAction produces.
     */
    private function seedReports(OutputInterface $output): void
    {
        if ($this->em->getRepository(Report::class)->count([]) > 0) {
            return;
        }
        $super = $this->em->getRepository(User::class)->findOneBy(['email' => 'surdbells@gmail.com']);
        if ($super === null) {
            return;
        }
        $by = static function (Report $r) use ($super): Report {
            $r->setGeneratedBy($super->getId(), $super->getFirstName() . ' ' . $super->getLastName());
            return $r;
        };

        // --- Platform overview (counts + averages) ---
        $institutions = (int) $this->em->getRepository(Institution::class)->count([]);
        $userCount = (int) $this->em->getRepository(User::class)->count([]);
        $avgRow = $this->em->createQueryBuilder()->select('AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')->where('at.status = :g')
            ->setParameter('g', AssessmentAttempt::GRADED)->getQuery()->getArrayResult();
        $avg = $avgRow[0]['avg'] ?? null;
        $avgLabel = $avg === null ? '—' : round((float) $avg, 1) . '%';

        // --- Subscriptions (real plans → active subs → MRR) ---
        $mrr = 0.0;
        $activeSubs = 0;
        $subRows = [];
        foreach ($this->em->getRepository(SubscriptionPlan::class)->findBy(['isActive' => true]) as $plan) {
            /** @var SubscriptionPlan $plan */
            $subs = $this->em->getRepository(Subscription::class)->findBy(['plan' => $plan]);
            $active = array_filter($subs, static fn (Subscription $s) => in_array($s->status(), [Subscription::ACTIVE, Subscription::GRACE], true));
            $price = $plan->getPriceKobo() / 100;
            $planMrr = count($active) * $price;
            $activeSubs += count($active);
            $mrr += $planMrr;
            $subRows[] = [$plan->getName(), count($subs), count($active), number_format($price), number_format($planMrr)];
        }

        $overview = $by(new Report('platform_overview', 'Platform overview'));
        $overview->setSummary([
            ['label' => 'Institutions', 'value' => $institutions],
            ['label' => 'Users', 'value' => $userCount],
            ['label' => 'Avg score', 'value' => $avgLabel],
            ['label' => 'MRR', 'value' => '₦' . number_format($mrr)],
        ]);
        $overview->setData(['columns' => ['Metric', 'Value'], 'rows' => [
            ['Institutions', $institutions],
            ['Users', $userCount],
            ['Average assessment score', $avgLabel],
            ['Active subscriptions', $activeSubs],
            ['Monthly recurring revenue', '₦' . number_format($mrr)],
        ]]);
        $this->em->persist($overview);

        $subReport = $by(new Report('subscriptions', 'Subscriptions & revenue'));
        $subReport->setSummary([
            ['label' => 'Active subscriptions', 'value' => $activeSubs],
            ['label' => 'MRR', 'value' => '₦' . number_format($mrr)],
            ['label' => 'ARR', 'value' => '₦' . number_format($mrr * 12)],
        ]);
        $subReport->setData(['columns' => ['Plan', 'Total subscribers', 'Active', 'Price (₦)', 'MRR (₦)'], 'rows' => $subRows]);
        $this->em->persist($subReport);

        $this->em->flush();
        $output->writeln('  + 2 platform reports');
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
