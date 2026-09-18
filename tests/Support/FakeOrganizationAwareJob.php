<?php

namespace Tests\Support;

use App\Contracts\OrganizationAwareJob;

class FakeOrganizationAwareJob implements OrganizationAwareJob
{
    public function __construct(
        private readonly string $actor,
        private readonly string $organization,
        private readonly string $idempotency = 'fake-job',
    ) {}

    public function actorId(): string
    {
        return $this->actor;
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotency;
    }
}
