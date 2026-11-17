<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\Billing\SubscriptionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Suspends tutor and learner access once an organisation's paid period and its
 * seven days of grace are over (and straight after the paid period when the
 * customer cancelled).
 *
 * Administrators are not suspended: they still sign in to see what happened,
 * pay, and ask for an export. Tutors, learners and parents can sign in and are
 * told why nothing else is available; their records are untouched. Renewing
 * restores access at once.
 *
 * Runs after JwtAuthMiddleware (the user is on the request).
 */
final class SubscriptionGateMiddleware implements MiddlewareInterface
{
    /** Roles whose access is suspended. */
    private const SUSPENDED_ROLES = [Role::TEACHER, Role::STUDENT, Role::PARENT];

    /** What a suspended user can still reach: their own session, account and notifications. */
    private const ALLOWED = ['/auth/', '/notifications', '/school/profile', '/files'];

    /** What the administrator of an organisation awaiting its first payment can reach. */
    private const ALLOWED_BEFORE_PAYMENT = ['/auth/', '/notifications', '/files', '/billing/', '/admin/institutions/', '/support/'];

    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $institution = $user?->getInstitution();
        if ($user === null || $institution === null) {
            return $handler->handle($request);
        }
        $path = preg_replace('#^/backend#', '', $request->getUri()->getPath()) ?? '';

        // Signed up online and not paid yet: only billing is open, for everyone.
        if ($institution->isAwaitingPayment()) {
            foreach (self::ALLOWED_BEFORE_PAYMENT as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $handler->handle($request);
                }
            }

            return Json::write(new SlimResponse(), [
                'error' => 'Choose a plan and complete the payment under Billing to open your account.',
                'code' => 'awaiting_payment',
            ], 402);
        }

        if (!in_array($user->getRole()->effectiveCode(), self::SUSPENDED_ROLES, true)) {
            return $handler->handle($request);
        }

        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        if (!$this->subscriptions->isSuspended($institution)) {
            return $handler->handle($request);
        }

        return Json::write(new SlimResponse(), [
            'error' => self::message($institution->getName()),
            'code' => 'subscription_suspended',
        ], 402);
    }

    public static function message(string $organisation): string
    {
        return sprintf(
            '%s\'s subscription has ended, so access for tutors and learners is paused. Your work and records are safe. Access returns as soon as the subscription is renewed; please speak to your administrator.',
            $organisation,
        );
    }
}
