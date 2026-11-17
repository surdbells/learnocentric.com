<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AccountMailer;
use App\Service\AcademicCalendar;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\NotificationService;
use App\Service\PlatformSettings;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Self onboarding from the public website.
 *
 *  GET  /auth/signup-options          (public) whether organisation sign-up is open
 *  POST /auth/signup-organisation     (public) {type, organisation_name, first_name, last_name,
 *                                               email, password, phone?, address?, website}
 *
 * Creates the organisation and its administrator. The organisation pays before
 * service begins: until its first payment is verified only billing is open, and
 * nobody but administrators can be added. When the platform requires email
 * confirmation (and mail works) the administrator confirms the address first.
 *
 * Abuse controls for an open endpoint: a honeypot field ("website", which people
 * never see), and per-sender and platform-wide hourly caps.
 */
final class OrganisationSignupAction
{
    private const PER_SENDER_PER_HOUR = 3;
    private const GLOBAL_PER_HOUR = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthService $auth,
        private readonly JwtService $jwt,
        private readonly AuditLogger $audit,
        private readonly PlatformSettings $settings,
        private readonly AccountMailer $accountMail,
        private readonly AcademicCalendar $calendar,
        private readonly NotificationService $notify,
    ) {
    }

    public function options(Request $request, Response $response): Response
    {
        $open = $this->settings->bool('registration.allow_org_signup');

        return Json::write($response, [
            'open' => $open,
            // A kind of organisation can sign up only while it has a plan it can buy online,
            // since the account opens with its first payment.
            'school' => $open && $this->hasPlanFor('school'),
            'academy' => $open && $this->hasPlanFor('academy'),
            'password_min_length' => $this->settings->passwordMinLength(),
            'email_confirmation' => $this->settings->bool('registration.require_email_verification') && $this->accountMail->canDeliver(),
        ]);
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->settings->bool('registration.allow_org_signup')) {
            return Json::error($response, 'New organisations cannot sign up online at the moment. Please contact us and we will set up your account.', 403);
        }
        $body = (array) $request->getParsedBody();

        // Bots fill every field; people never see this one. Pretend it worked, create nothing.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return Json::write($response, ['verification_required' => true, 'email' => (string) ($body['email'] ?? '')], 201);
        }

        $name = trim((string) ($body['organisation_name'] ?? ''));
        $first = trim((string) ($body['first_name'] ?? ''));
        $last = trim((string) ($body['last_name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $phone = trim((string) ($body['phone'] ?? ''));
        $type = ($body['type'] ?? 'school') === 'academy' ? 'tutoring_academy' : 'school';
        $noun = $type === 'school' ? 'school' : 'academy';
        if (!$this->hasPlanFor($noun)) {
            return Json::error($response, sprintf('Online sign-up is not open for %s yet. Please contact us and we will set up your account.', $noun === 'school' ? 'schools' : 'academies'), 403);
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            return Json::error($response, sprintf('Please enter your %s\'s name.', $noun), 422);
        }
        if ($first === '' || $last === '' || mb_strlen($first) > 80 || mb_strlen($last) > 80) {
            return Json::error($response, 'Please enter your first and last name.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return Json::error($response, 'Please enter a valid email address.', 422);
        }
        $min = $this->settings->passwordMinLength();
        if (mb_strlen($password) < $min) {
            return Json::error($response, sprintf('Choose a password of at least %d characters.', $min), 422);
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
            return Json::error($response, 'Please enter a valid phone number, or leave it empty.', 422);
        }
        if ($this->auth->findByEmail($email) !== null) {
            return Json::error($response, 'An account with this email already exists. Sign in instead, or use "Forgot password" if you need a new one.', 409);
        }

        // Rate limits, counted on organisations that signed themselves up.
        $ipHash = hash('sha256', 'org-signup|' . (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $since = new DateTimeImmutable('-1 hour');
        $recent = $this->em->createQueryBuilder()->select('i.adminContact AS c')->from(Institution::class, 'i')
            ->where('i.signupSource = :self')->andWhere('i.createdAt > :since')
            ->setParameter('self', 'self')->setParameter('since', $since)
            ->getQuery()->getArrayResult();
        $fromSender = count(array_filter($recent, static fn (array $r) => ($r['c']['signup_ip'] ?? null) === $ipHash));
        if ($fromSender >= self::PER_SENDER_PER_HOUR || count($recent) >= self::GLOBAL_PER_HOUR) {
            return Json::error($response, 'We have received several sign-ups recently. Please try again in a little while, or contact us.', 429);
        }

        $this->em->beginTransaction();
        try {
            $institution = new Institution($name);
            $institution->setType($type);
            $address = trim((string) ($body['address'] ?? ''));
            $institution->setAddress($address !== '' ? mb_substr($address, 0, 500) : null);
            $institution->setAdminContact([
                'email' => $email, 'phone' => $phone, 'first_name' => $first, 'last_name' => $last, 'signup_ip' => $ipHash,
            ]);
            $institution->setSignupSource('self');
            $institution->setAwaitingPayment(true);
            $this->em->persist($institution);
            $this->em->flush();
            $this->calendar->ensureFor($institution);

            $admin = $this->auth->register([
                'email' => $email,
                'password' => $password,
                'firstName' => $first,
                'lastName' => $last,
                'phone' => $phone !== '' ? $phone : null,
                'role' => $institution->adminRoleCode(),
                'institutionId' => $institution->getId(),
                // Self sign-up must prove the address.
                'verified' => false,
            ]);
            $this->em->commit();
        } catch (Throwable $e) {
            $this->em->rollback();

            return Json::error($response, 'We could not create the account just now. Please try again.', 500);
        }

        $this->audit->log('institution.self_signup', $admin, 'Institution', (string) $institution->getId(), null, [
            'name' => $name, 'type' => $type, 'admin' => $admin->getId(),
        ]);
        foreach ($this->platformOwners() as $owner) {
            $this->notify->notify($owner, 'system', sprintf('New %s signed up: %s', $noun, $name), sprintf(
                '%s signed up %s on the website. The account opens once its first payment is verified.',
                $admin->displayName(), $name,
            ), '/super-admin/institutions', false);
        }
        $this->em->flush();

        if ($this->settings->bool('registration.require_email_verification') && $this->accountMail->canDeliver()) {
            $this->accountMail->sendEmailVerification($admin);

            return Json::write($response, [
                'verification_required' => true,
                'email' => $admin->getEmail(),
                'message' => sprintf('We have sent a confirmation link to %s. Open it, then sign in to choose your plan.', $admin->getEmail()),
            ], 201);
        }
        $admin->markEmailVerified();
        $this->em->flush();

        return Json::write($response, [
            'token' => $this->jwt->issueForUser($admin, $this->settings->sessionSeconds()),
            'user' => $admin->toArray(),
        ], 201);
    }

    /** Is at least one plan for this kind of organisation on sale and payable online? */
    private function hasPlanFor(string $audience): bool
    {
        foreach ($this->em->getRepository(\App\Domain\Entity\SubscriptionPlan::class)->findBy(['isActive' => true, 'audience' => $audience]) as $plan) {
            if ($plan->isPurchasable()) {
                return true;
            }
        }

        return false;
    }

    /** @return User[] */
    private function platformOwners(): array
    {
        return $this->em->createQueryBuilder()->select('u')
            ->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.effectiveCode = :role')->andWhere('u.status = :active')
            ->setParameter('role', Role::SUPER_ADMIN)->setParameter('active', 'active')
            ->getQuery()->getResult();
    }
}
