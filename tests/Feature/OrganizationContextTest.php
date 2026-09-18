<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeOrganizationAwareJob;
use Tests\TestCase;

class OrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_context_revalidates_membership_before_running(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => UserRole::Editor,
        ]);

        $job = new FakeOrganizationAwareJob($user->id, $organization->id);

        $ran = app(UseOrganizationContext::class)->handle(
            $job,
            fn (): bool => true,
        );

        $this->assertTrue($ran);
    }

    public function test_queue_context_rejects_foreign_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $job = new FakeOrganizationAwareJob($user->id, $organization->id);

        $this->expectException(AuthorizationException::class);

        app(UseOrganizationContext::class)->handle(
            $job,
            fn (): bool => true,
        );
    }
}
