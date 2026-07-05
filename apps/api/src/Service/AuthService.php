<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

class AuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordService $passwords,
    ) {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
    }

    public function findById(int $id): ?User
    {
        return $this->em->getRepository(User::class)->find($id);
    }

    /** Verify credentials; returns the user on success, null on failure. */
    public function attempt(string $email, string $password): ?User
    {
        $user = $this->findByEmail($email);
        if ($user === null || $user->getStatus() !== 'active') {
            return null;
        }
        if (!$this->passwords->verify($password, $user->getPasswordHash())) {
            return null;
        }

        $user->markLoggedIn();
        $this->em->flush();

        return $user;
    }

    /**
     * @param array{email:string,password:string,firstName:string,lastName:string,role?:string,institutionId?:int|null,phone?:string|null} $data
     */
    public function register(array $data): User
    {
        if ($this->findByEmail($data['email']) !== null) {
            throw new RuntimeException('A user with this email already exists.');
        }

        $roleCode = $data['role'] ?? Role::STUDENT;
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $roleCode]);
        if ($role === null) {
            throw new RuntimeException("Unknown role: {$roleCode}");
        }

        $user = new User($data['email'], $data['firstName'], $data['lastName'], $role);
        $user->setPasswordHash($this->passwords->hash($data['password']));
        $user->setPhone($data['phone'] ?? null);

        if (!empty($data['institutionId'])) {
            $institution = $this->em->getRepository(Institution::class)->find((int) $data['institutionId']);
            $user->setInstitution($institution);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
