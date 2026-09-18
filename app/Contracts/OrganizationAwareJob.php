<?php

namespace App\Contracts;

interface OrganizationAwareJob
{
    public function actorId(): string;

    public function organizationId(): string;

    /**
     * Stable key identifying the same unit of work across retries.
     */
    public function idempotencyKey(): string;
}
