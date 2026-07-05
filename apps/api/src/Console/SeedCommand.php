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
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
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
        $this->seedNotifications($output);
        $this->seedTopicProgress($output);
        $this->seedInterventions($output);
        $this->seedSafeguarding($output);
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
        $one->setDueDate(new DateTimeImmutable('+5 days'));
        $this->em->persist($one);

        if ($chiamaka !== null) {
            $two = new Intervention($chiamaka, 'Borderline pass (50%) — monitor and revisit fractions.');
            $two->setSubject($subject);
            $two->setRaisedBy($teacher);
            $two->setAssignedTo($teacher);
            $two->setStatus(Intervention::OPEN);
            $two->setDueDate(new DateTimeImmutable('+10 days'));
            $this->em->persist($two);
        }
        $this->em->flush();
        $output->writeln('  + 2 interventions');
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
                ['assessments', 'worksheets', 'portfolio', 'live_classes', 'analytics']],
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

    /** Seed the platform subject catalogue and link existing school subjects to it. */
    private function seedCatalogSubjects(OutputInterface $output): void
    {
        if ($this->em->getRepository(CatalogSubject::class)->count([]) > 0) {
            return;
        }
        // [name, code, curriculum]
        $defs = [
            ['Mathematics', 'MTH', 'NERDC'],
            ['English Language', 'ENG', 'NERDC'],
            ['Basic Science', 'BSC', 'NERDC'],
            ['Basic Technology', 'BTECH', 'NERDC'],
            ['Social Studies', 'SOS', 'NERDC'],
            ['Civic Education', 'CIV', 'NERDC'],
            ['Computer Studies', 'COMP', 'NERDC'],
            ['Agricultural Science', 'AGRIC', 'NERDC'],
        ];
        $byCode = [];
        foreach ($defs as [$name, $code, $curriculum]) {
            $c = new CatalogSubject($name, $code);
            $c->setCurriculum($curriculum);
            $c->setDescription($name . ' — Nigerian junior-secondary curriculum.');
            $this->em->persist($c);
            $byCode[$code] = $c;
        }
        $this->em->flush();

        // Link the seeded institution subjects to their catalogue entry by code.
        foreach ($this->em->getRepository(Subject::class)->findAll() as $subject) {
            $code = strtoupper((string) ($subject->toArray()['code'] ?? ''));
            if (isset($byCode[$code])) {
                $subject->setCatalogSubject($byCode[$code]);
            }
        }
        $this->em->flush();

        $output->writeln('  + ' . count($defs) . ' catalogue subjects (linked to school subjects)');
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

        $this->em->persist(new Announcement($institution, $admin, 'Mid-term break', 'School closes for mid-term on Friday and resumes the following Monday.', 'all'));
        $this->em->persist(new Announcement($institution, $admin, 'Staff briefing', 'All teachers, please meet in the staff room at 8am tomorrow.', 'staff'));
        $this->em->flush();

        $output->writeln('  + 2 messages, 2 announcements');
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
        $teacher = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :t')->andWhere('u.institution = :i')
            ->setParameter('t', 'teacher')->setParameter('i', $institution)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        $room = static function (string $title): array {
            $name = 'learno-' . substr(md5($title), 0, 12);
            return [$name, 'https://learnocentric.daily.co/' . $name];
        };

        // A scheduled class (tomorrow) and one currently live.
        [$n1, $u1] = $room('Whole Numbers Revision');
        $scheduled = new LiveClass($subject, 'Whole Numbers — Live Revision', new DateTimeImmutable('tomorrow 10:00'));
        $scheduled->setSchoolClass($class);
        $scheduled->setTopic($topic);
        $scheduled->setHost($teacher);
        $scheduled->setDurationMinutes(45);
        $scheduled->setRoomName($n1);
        $scheduled->setRoomUrl($u1);
        $scheduled->setStatus(LiveClass::SCHEDULED);
        $this->em->persist($scheduled);

        // A second class starting soon. Left SCHEDULED (not pre-LIVE): going live
        // through the app provisions a real Daily room so the embedded call works.
        [$n2, $u2] = $room('Fractions Live Q and A');
        $live = new LiveClass($subject, 'Fractions — Live Q&A', new DateTimeImmutable('+30 minutes'));
        $live->setSchoolClass($class);
        $live->setHost($teacher);
        $live->setDurationMinutes(30);
        $live->setRoomName($n2);
        $live->setRoomUrl($u2);
        $live->setStatus(LiveClass::SCHEDULED);
        $this->em->persist($live);
        $this->em->flush();

        $student = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :s')->andWhere('u.institution = :i')
            ->setParameter('s', 'student')->setParameter('i', $institution)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($student !== null) {
            $this->em->persist(new LiveClassAttendance($live, $student, new DateTimeImmutable('-8 minutes')));
            $this->em->flush();
        }
        $output->writeln('  + 2 live classes (1 attendee)');
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

        // [student index, type, topic?, message, acknowledged]
        $notes = [
            [0, 'correction', $topic, 'You mixed up place value in Q3 — remember the hundreds column. Redo questions 3 and 7.', false],
            [1, 'praise', null, 'Excellent improvement this week — your working is much clearer. Keep it up!', true],
        ];
        foreach ($notes as [$idx, $type, $noteTopic, $message, $ack]) {
            if (!isset($students[$idx])) {
                continue;
            }
            $note = new FeedbackNote($students[$idx], $message);
            $note->setAuthor($teacher);
            $note->setType($type);
            $note->setTopic($noteTopic);
            if ($ack) {
                $note->setAcknowledged(true);
                $note->setAcknowledgedAt(new DateTimeImmutable('-6 hours'));
            }
            $this->em->persist($note);
        }
        $this->em->flush();
        $output->writeln('  + 2 feedback notes');
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
        $worksheet->setInstructions('Complete all 10 questions in your exercise book, then upload a photo or type your answers.');
        $worksheet->setTotalMarks(10);
        $worksheet->setDueDate(new DateTimeImmutable('+7 days'));
        $worksheet->setApprovalStatus(Lifecycle::PUBLISHED);
        $this->em->persist($worksheet);
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

        foreach ($students as $i => $student) {
            $submission = new WorksheetSubmission($worksheet, $student);
            $submission->setResponseText('My worked answers for questions 1 to 10.');
            $submission->setSubmittedAt(new DateTimeImmutable('-1 day'));
            if ($i === 0) {
                $submission->setScore(8);
                $submission->setFeedback('Good work — revisit place value in Q3 and Q7.');
                $submission->setStatus(WorksheetSubmission::GRADED);
                $submission->setGradedAt(new DateTimeImmutable('-12 hours'));
            } else {
                $submission->setStatus(WorksheetSubmission::SUBMITTED);
            }
            $this->em->persist($submission);
        }
        $this->em->flush();
        $output->writeln('  + 1 worksheet (' . count($students) . ' submissions)');
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
