<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gf_identity_memberships')]
#[ORM\UniqueConstraint(name: 'uq_gf_identity_memberships_actor', columns: ['organization_id', 'user_id'])]
class IdentityMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: IdentityUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private IdentityUser $user;

    #[ORM\ManyToOne(targetEntity: IdentityOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private IdentityOrganization $organization;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
