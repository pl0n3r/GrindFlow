<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gf_identity_organizations')]
#[ORM\UniqueConstraint(name: 'uq_gf_identity_organizations_slug', columns: ['slug'])]
class IdentityOrganization
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 50)]
    private string $slug;

    #[ORM\Column(length: 16)]
    private string $type = 'independent';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
