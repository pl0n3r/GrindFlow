<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'gf_identity_users')]
#[ORM\UniqueConstraint(name: 'uq_gf_identity_users_email', columns: ['email'])]
class IdentityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'platform_role', length: 16)]
    private string $platformRole = 'model';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'session_generation', type: 'integer', options: ['unsigned' => true])]
    private int $sessionGeneration = 0;

    #[ORM\Column(name: 'last_access_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAccessAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && hash_equals($this->email, $user->email)
            && hash_equals($this->passwordHash, $user->passwordHash)
            && $this->active === $user->active
            && $this->platformRole === $user->platformRole
            && $this->sessionGeneration === $user->sessionGeneration;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }
}
