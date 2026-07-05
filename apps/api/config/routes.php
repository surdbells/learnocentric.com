<?php

declare(strict_types=1);

use App\Application\Actions\Auth\LoginAction;
use App\Application\Actions\Auth\MeAction;
use App\Application\Actions\Auth\ProfileAction;
use App\Application\Actions\Auth\RegisterAction;
use App\Application\Actions\Analytics\AnalyticsAction;
use App\Application\Actions\Dashboard\DashboardAction;
use App\Application\Actions\Auth\UserProfileAction;
use App\Application\Actions\Billing\BillingAction;
use App\Application\Actions\Billing\PlansAction;
use App\Application\Actions\Assessment\AssessmentsAction;
use App\Application\Actions\Assessment\AttemptsAction;
use App\Application\Actions\Assessment\FeedbackAction;
use App\Application\Actions\Assessment\GradebookAction;
use App\Application\Actions\Assessment\InsightsAction;
use App\Application\Actions\Assessment\PortfolioAction;
use App\Application\Actions\Assessment\QuestionsAction;
use App\Application\Actions\Assessment\WorksheetsAction;
use App\Application\Actions\Assessment\WorksheetSubmissionsAction;
use App\Application\Actions\Content\ContentLibraryAction;
use App\Application\Actions\Content\ContentPackagesAction;
use App\Application\Actions\Curriculum\DeliveryPacksAction;
use App\Application\Actions\Curriculum\ReviewQueueAction;
use App\Application\Actions\Curriculum\TopicsAction;
use App\Application\Actions\HealthAction;
use App\Application\Actions\Institution\GetInstitutionAction;
use App\Application\Actions\Learn\LearnAction;
use App\Application\Actions\Live\LiveClassesAction;
use App\Application\Actions\Notification\NotificationsAction;
use App\Application\Actions\Institution\ListInstitutionsAction;
use App\Application\Actions\Institution\OnboardInstitutionAction;
use App\Application\Actions\School\ClassesAction;
use App\Application\Actions\School\EnrollmentsAction;
use App\Application\Actions\School\InterventionsAction;
use App\Application\Actions\School\SafeguardingAction;
use App\Application\Actions\School\SchemeOfWorkAction;
use App\Application\Actions\School\SchoolProfileAction;
use App\Application\Actions\School\StudentsAction;
use App\Application\Actions\School\TermsAction;
use App\Application\Actions\School\SubjectsAction;
use App\Application\Actions\School\TeachersAction;
use App\Application\Actions\Storage\UploadAction;
use App\Application\Middleware\JwtAuthMiddleware;
use App\Application\Middleware\ModuleGateMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/health', HealthAction::class);

    $app->group('/backend', function (RouteCollectorProxy $group): void {
        // --- Public ---
        $group->post('/auth/login', LoginAction::class);
        $group->post('/auth/register', RegisterAction::class);

        // --- Authenticated ---
        $group->group('', function (RouteCollectorProxy $auth): void {
            $auth->get('/auth/me', MeAction::class);
            $auth->map(['GET', 'PUT'], '/auth/profile', ProfileAction::class);
            $auth->map(['GET', 'PUT', 'DELETE'], '/auth/user-profile/{id:[0-9]+}', UserProfileAction::class);

            $auth->get('/admin/institutions', ListInstitutionsAction::class);
            $auth->get('/admin/institutions/{id:[0-9]+}', GetInstitutionAction::class);
            $auth->post('/admin/onboard', OnboardInstitutionAction::class);

            // Academic spine
            $auth->map(['GET', 'DELETE'], '/school/students', StudentsAction::class);
            $auth->post('/school/students/bulk-delete', StudentsAction::class . ':bulkDelete');
            $auth->map(['GET', 'DELETE'], '/school/teachers', TeachersAction::class);
            $auth->post('/school/teachers/bulk-delete', TeachersAction::class . ':bulkDelete');
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/school/subjects', SubjectsAction::class);
            $auth->post('/school/subjects/bulk-delete', SubjectsAction::class . ':bulkDelete');
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/school/classes', ClassesAction::class);
            $auth->post('/school/classes/bulk-delete', ClassesAction::class . ':bulkDelete');
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/school/enrollments', EnrollmentsAction::class);
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/school/scheme-of-work', SchemeOfWorkAction::class);
            $auth->post('/school/scheme-of-work/bulk-delete', SchemeOfWorkAction::class . ':bulkDelete');
            $auth->get('/school/terms', TermsAction::class);
            $auth->map(['GET', 'PUT'], '/school/profile', SchoolProfileAction::class);
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/school/interventions', InterventionsAction::class);
            $auth->post('/school/interventions/bulk-delete', InterventionsAction::class . ':bulkDelete');
            $auth->map(['GET', 'POST', 'PUT'], '/safeguarding/cases', SafeguardingAction::class);

            // Curriculum + content lifecycle
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/curriculum/topics', TopicsAction::class);
            $auth->post('/curriculum/topics/bulk-delete', TopicsAction::class . ':bulkDelete');
            $auth->post('/curriculum/topics/{id:[0-9]+}/transition', TopicsAction::class . ':transition');
            $auth->get('/curriculum/topics/{id:[0-9]+}/history', TopicsAction::class . ':history');
            $auth->get('/curriculum/review-queue', ReviewQueueAction::class);

            // Delivery packs — author lesson content (staff), lifecycle-governed
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/curriculum/delivery-packs', DeliveryPacksAction::class);
            $auth->post('/curriculum/delivery-packs/bulk-delete', DeliveryPacksAction::class . ':bulkDelete');
            $auth->post('/curriculum/delivery-packs/{id:[0-9]+}/transition', DeliveryPacksAction::class . ':transition');
            $auth->get('/curriculum/delivery-packs/{id:[0-9]+}/history', DeliveryPacksAction::class . ':history');

            // Assessment — question bank (answer-validation gate) + lifecycle
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/assessment/questions', QuestionsAction::class);
            $auth->post('/assessment/questions/bulk-delete', QuestionsAction::class . ':bulkDelete');
            $auth->post('/assessment/questions/{id:[0-9]+}/validate', QuestionsAction::class . ':validate');
            $auth->post('/assessment/questions/{id:[0-9]+}/transition', QuestionsAction::class . ':transition');
            $auth->get('/assessment/questions/{id:[0-9]+}/history', QuestionsAction::class . ':history');

            // Assessment — quiz/test builder assembled from validated questions
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/assessment/assessments', AssessmentsAction::class);
            $auth->post('/assessment/assessments/bulk-delete', AssessmentsAction::class . ':bulkDelete');
            $auth->get('/assessment/assessments/{id:[0-9]+}', AssessmentsAction::class . ':show');
            $auth->post('/assessment/assessments/{id:[0-9]+}/questions', AssessmentsAction::class . ':attach');
            $auth->delete('/assessment/assessments/{id:[0-9]+}/questions/{qid:[0-9]+}', AssessmentsAction::class . ':detach');
            $auth->post('/assessment/assessments/{id:[0-9]+}/transition', AssessmentsAction::class . ':transition');
            $auth->get('/assessment/assessments/{id:[0-9]+}/history', AssessmentsAction::class . ':history');

            // Assessment — learner attempts (take + auto-grade)
            $auth->get('/assessment/attempts/available', AttemptsAction::class . ':available');
            $auth->post('/assessment/attempts', AttemptsAction::class . ':start');
            $auth->get('/assessment/attempts/{id:[0-9]+}', AttemptsAction::class . ':show');
            $auth->post('/assessment/attempts/{id:[0-9]+}/submit', AttemptsAction::class . ':submit');

            // Assessment — gradebook (staff)
            $auth->get('/assessment/gradebook', GradebookAction::class . ':overview');
            $auth->get('/assessment/gradebook/students', GradebookAction::class . ':students');
            $auth->get('/assessment/gradebook/{id:[0-9]+}', GradebookAction::class . ':assessment');

            // Worksheets — topic-linked, staff CRUD + lifecycle, student submit + staff grade
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/assessment/worksheets', WorksheetsAction::class);
            $auth->post('/assessment/worksheets/bulk-delete', WorksheetsAction::class . ':bulkDelete');
            $auth->get('/assessment/worksheets/available', WorksheetSubmissionsAction::class . ':available');
            $auth->post('/assessment/worksheets/{id:[0-9]+}/transition', WorksheetsAction::class . ':transition');
            $auth->get('/assessment/worksheets/{id:[0-9]+}/history', WorksheetsAction::class . ':history');
            $auth->post('/assessment/worksheets/{id:[0-9]+}/submit', WorksheetSubmissionsAction::class . ':submit');
            $auth->get('/assessment/worksheets/{id:[0-9]+}/submission', WorksheetSubmissionsAction::class . ':mine');
            $auth->get('/assessment/worksheets/{id:[0-9]+}/submissions', WorksheetSubmissionsAction::class . ':submissions');
            $auth->post('/assessment/worksheet-submissions/{id:[0-9]+}/grade', WorksheetSubmissionsAction::class . ':grade');

            // Portfolio — competency-track evidence (student submit, staff review)
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/assessment/portfolio', PortfolioAction::class);
            $auth->get('/assessment/portfolio/mine', PortfolioAction::class . ':mine');
            $auth->get('/assessment/portfolio/topics', PortfolioAction::class . ':topics');
            $auth->post('/assessment/portfolio/{id:[0-9]+}/review', PortfolioAction::class . ':review');

            // Feedback + misconception loop
            $auth->map(['GET', 'POST'], '/assessment/feedback', FeedbackAction::class);
            $auth->get('/assessment/feedback/mine', FeedbackAction::class . ':mine');
            $auth->post('/assessment/feedback/{id:[0-9]+}/acknowledge', FeedbackAction::class . ':acknowledge');
            $auth->get('/assessment/insights', InsightsAction::class);

            // Live classes (Daily.co) — schedule, run, join, attendance
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/live-classes', LiveClassesAction::class);
            $auth->post('/live-classes/bulk-delete', LiveClassesAction::class . ':bulkDelete');
            $auth->get('/live-classes/upcoming', LiveClassesAction::class . ':upcoming');
            $auth->post('/live-classes/{id:[0-9]+}/start', LiveClassesAction::class . ':start');
            $auth->post('/live-classes/{id:[0-9]+}/end', LiveClassesAction::class . ':end');
            $auth->post('/live-classes/{id:[0-9]+}/join', LiveClassesAction::class . ':join');
            $auth->get('/live-classes/{id:[0-9]+}/attendance', LiveClassesAction::class . ':attendance');

            // Dashboards (per role)
            $auth->get('/dashboard/admin', DashboardAction::class . ':admin');
            $auth->get('/dashboard/teacher', DashboardAction::class . ':teacher');
            $auth->get('/dashboard/student', DashboardAction::class . ':student');
            $auth->get('/dashboard/super-admin', DashboardAction::class . ':superAdmin');
            $auth->get('/dashboard/parent', DashboardAction::class . ':parent');

            // Analytics — school overview (staff) + student progress report (self/guardian/staff)
            $auth->get('/analytics/overview', AnalyticsAction::class . ':overview');
            $auth->get('/analytics/children', AnalyticsAction::class . ':children');
            $auth->get('/analytics/student/{id:[0-9]+}', AnalyticsAction::class . ':student');

            // Content library + packages (super-admin supply chain, licensing/takedown)
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/content/library', ContentLibraryAction::class);
            $auth->get('/content/packages/{id:[0-9]+}', ContentPackagesAction::class . ':show');
            $auth->map(['GET', 'POST', 'DELETE'], '/content/packages', ContentPackagesAction::class);

            // Billing (Paystack) — plan catalogue, subscribe/verify, invoices
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/billing/plans', PlansAction::class);
            $auth->get('/billing/subscription', BillingAction::class . ':current');
            $auth->post('/billing/subscribe', BillingAction::class . ':subscribe');
            $auth->post('/billing/verify', BillingAction::class . ':verify');
            $auth->get('/billing/transactions', BillingAction::class . ':transactions');

            // Learn — student topic journey (lesson + stage progress)
            $auth->get('/learn/topics', LearnAction::class . ':topics');
            $auth->get('/learn/topics/{id:[0-9]+}', LearnAction::class . ':lesson');
            $auth->post('/learn/topics/{id:[0-9]+}/complete-lesson', LearnAction::class . ':completeLesson');

            // Notifications (in-app inbox)
            $auth->get('/notifications', NotificationsAction::class . ':mine');
            $auth->post('/notifications/read-all', NotificationsAction::class . ':readAll');
            $auth->post('/notifications/{id:[0-9]+}/read', NotificationsAction::class . ':read');

            $auth->post('/upload', UploadAction::class);
        })->add(ModuleGateMiddleware::class)->add(JwtAuthMiddleware::class);
    });
};
