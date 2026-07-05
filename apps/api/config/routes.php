<?php

declare(strict_types=1);

use App\Application\Actions\Auth\LoginAction;
use App\Application\Actions\Auth\MeAction;
use App\Application\Actions\Auth\ProfileAction;
use App\Application\Actions\Auth\RegisterAction;
use App\Application\Actions\Auth\UserProfileAction;
use App\Application\Actions\Assessment\QuestionsAction;
use App\Application\Actions\Curriculum\ReviewQueueAction;
use App\Application\Actions\Curriculum\TopicsAction;
use App\Application\Actions\HealthAction;
use App\Application\Actions\Institution\GetInstitutionAction;
use App\Application\Actions\Institution\ListInstitutionsAction;
use App\Application\Actions\Institution\OnboardInstitutionAction;
use App\Application\Actions\School\ClassesAction;
use App\Application\Actions\School\EnrollmentsAction;
use App\Application\Actions\School\StudentsAction;
use App\Application\Actions\School\SubjectsAction;
use App\Application\Actions\School\TeachersAction;
use App\Application\Actions\Storage\UploadAction;
use App\Application\Middleware\JwtAuthMiddleware;
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

            // Curriculum + content lifecycle
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/curriculum/topics', TopicsAction::class);
            $auth->post('/curriculum/topics/bulk-delete', TopicsAction::class . ':bulkDelete');
            $auth->post('/curriculum/topics/{id:[0-9]+}/transition', TopicsAction::class . ':transition');
            $auth->get('/curriculum/topics/{id:[0-9]+}/history', TopicsAction::class . ':history');
            $auth->get('/curriculum/review-queue', ReviewQueueAction::class);

            // Assessment — question bank (answer-validation gate) + lifecycle
            $auth->map(['GET', 'POST', 'PUT', 'DELETE'], '/assessment/questions', QuestionsAction::class);
            $auth->post('/assessment/questions/bulk-delete', QuestionsAction::class . ':bulkDelete');
            $auth->post('/assessment/questions/{id:[0-9]+}/validate', QuestionsAction::class . ':validate');
            $auth->post('/assessment/questions/{id:[0-9]+}/transition', QuestionsAction::class . ':transition');
            $auth->get('/assessment/questions/{id:[0-9]+}/history', QuestionsAction::class . ':history');

            $auth->post('/upload', UploadAction::class);
        })->add(JwtAuthMiddleware::class);
    });
};
